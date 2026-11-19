# Architecture

Depth behind the [README](README.md). The decisions themselves live in
[`docs/decisions/`](docs/decisions/); this is how the pieces fit.

## Layers

```
Kernel ◀── Infrastructure ◀── Application ◀── Domain
                                   │
                               depends on Domain
                               and on interfaces
```

| Layer | Contents | May depend on |
|---|---|---|
| `Domain` | `OrderStatus`, `StateMachine`, `InvalidTransition`, `Money` | nothing |
| `Application` | `IngestWebhookEvent`, `DispatchOrder`, `ProcessQueue`, `ReconcileDeadLetters`, `Backoff` | `Domain`, interfaces |
| `Infrastructure` | repositories, `Migrator`, `ErpGateway` implementations | `Domain`, `Application`, `Kernel` |
| `Kernel` | `Database`, `Config`, `Logger`, `Clock`, `App` | nothing above |

`Domain` has no `use` of PDO, no clock, no logger. Whether
`dispatching → registered` is legal is a question about the business, and
answering it should not require a database connection.

`Application` depends on the `ErpGateway` **interface**. That inversion is what
makes "the downstream system commits and then times out" an assertion rather
than a thought experiment — `FakeErp::$loseNextResponse` reproduces it on
demand.

## The order lifecycle

```
            webhook
               │
       ┌───────┴────────┐
       ▼                ▼
  payment_pending ──▶ ready ◀──────────────┐
       │               │                   │
       │               ▼                   │ operator fixes the data
       │          dispatching              │
       │           │  │  │  │              │
       │   ┌───────┘  │  │  └──────┐       │
       ▼   ▼          ▼  ▼         ▼       │
   cancelled    registered  failed   blocked
   (terminal)   (terminal)    │         ▲
                              │         │
                        budget spent    │
                              ▼         │
                        dead_letter ────┘
                          │      │
            operator retry│      │reconciliation finds it downstream
                          ▼      ▼
                    dispatching  registered
```

Terminal: `registered`, `cancelled`. Only those two.
Claimable by the queue: `ready`, `failed`. Only those two.
Waiting for a human: `dead_letter`, `blocked` — neither terminal nor claimable.

Those are three different questions and three separate lists. Collapsing any
two of them is [ADR-0004](docs/decisions/0004-waiting-states-are-not-terminal.md).

## Schema

| Table | Role | Key constraint |
|---|---|---|
| `orders` | the order **and** its queue state | `UNIQUE (reference)` |
| `inbox_events` | one row per webhook delivery | `UNIQUE (source, external_event_id)` |
| `order_transitions` | audit, one row per accepted change | — |
| `dispatch_operations` | idempotency ledger for outbound writes | `UNIQUE (idempotency_key)` |

Three of the four unique constraints are not hygiene, they are the mechanism:

- `orders.reference` is what makes two concurrent events for a new order resolve
  to one row. A `SELECT` before the `INSERT` has a window between the two, and
  webhook senders retry fast enough to find it.
- `inbox_events` is what makes a redelivery a no-op.
- `dispatch_operations.idempotency_key` is what makes a lost response
  recoverable.

Indexes:

```sql
CREATE INDEX ix_orders_claim ON orders (status, next_attempt_at, lease_expires_at);
```

Column order matters. `status` narrows hardest, then the two time predicates.
Without it the claim degrades into a full scan *while holding row locks*, which
is the slowest possible way to hold a lock.

### One schema, two engines

Migrations are plain `.sql` with four placeholders — `{{PK}}`, `{{DATETIME}}`,
`{{JSON}}`, `{{ENGINE}}` — substituted per driver by `Migrator`. One definition
rather than two parallel sets of files, because parallel files drift: someone
adds an index to the MySQL copy, forgets the SQLite one, and the suite stops
exercising the index production depends on.

`Migrator::splitStatements()` is a small scanner rather than
`explode(';', $sql)`. It tracks quoted strings and strips line comments *before*
splitting. Both details were learned here: a comment reading `"a disk fills up
at 03:00; the digest still answers"` was cut in half by the naive version, and
the engine received a `CREATE TABLE` missing its second half.

## The queue cycle

`ProcessQueue::runCycle()`:

1. `releaseExpiredLeases()` — reclaim work from dead workers.
2. `claimBatch()` — atomic claim, lease, `attempts + 1`, audit rows. One
   transaction.
3. For each claimed order, `DispatchOrder::dispatch()`, then act on the outcome.

Two conditions stop a batch early, both because continuing is worse than
stopping:

**Downstream unavailable.** Every remaining order would wait the same timeout
for the same refusal — a batch of 20 against a 60-second timeout is 20 minutes
of doing nothing. The rest go back to the queue unpenalised and the next cycle
probes with one order instead of twenty.

**Database connection lost.** The remaining orders cannot even have their
failures recorded. They are left to lease expiry, which exists for exactly this.

The cycle does one job. Dead-letter reconciliation used to run here too and was
moved to its own schedule: it re-read the same rows every cycle, most of which
could never be reconciled, and it did so while holding the window paying orders
needed. Work that is not on the critical path should not run on the critical
path's schedule.

## Dispatch, in order

`DispatchOrder::dispatch()` asks two questions before writing anything:

1. **"Did I already do this?"** — a `dispatch_operations` row marked `ok` means
   a previous attempt succeeded and only the status update was lost. Repair the
   status; call nobody.
2. **"Did I do this and lose the answer?"** — a row left `pending` means a write
   was issued and the outcome never recorded. Call
   `findOrderReference()`. Found: record it. Not found: safe to write. *Lookup
   failed: write nothing* — proceeding while unable to verify is precisely the
   duplicate the ledger exists to prevent.

Only then does it call `registerOrder()`.

## Failure taxonomy

| Exception | Meaning | Response | Attempt? |
|---|---|---|---|
| `ErpUnavailable` | down, timeout, 5xx, 429 | return batch, halt cycle | refunded |
| `ErpRejected` | 4xx, business rule | → `blocked`, wait for a human | spent |
| anything else | unknown | retry with backoff, then dead-letter | spent |

`HttpErpClient` does this classification, which makes it the most consequential
code in the class. A `2xx` with no identifier in the body is mapped to
`ErpUnavailable`, not success: something was probably created and we cannot name
it, so reconciliation should go and look.

## Time

Every deadline here is a timestamp comparison — a lease expires, a retry becomes
due. `Clock` is an interface; `SystemClock` is UTC; `FrozenClock` lets the tests
and the demos advance time explicitly.

`FrozenClock` lives in `src/`, not `tests/`, because `bin/demo.php` needs it: a
backoff demonstration that waited for real seconds would take an hour.

## Wiring

`Kernel\App` builds the object graph by hand, lazily, memoising each node. A
dozen nodes fit on a screen, and a reader who wants to know what talks to what
can find out by reading one file. A container earns its complexity at a scale
this project does not reach.
