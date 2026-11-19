# Transactional Integration Engine

A webhook-driven order integration engine in PHP 8, built around one question:
**what happens when the call succeeded but the answer was lost?**

Independent portfolio project. Fictional companies, synthetic data, original
code — see [Independence](#independence).

---

## The engineering problem

Moving an order from a storefront into a system of record looks like an HTTP
call. It is not. The call sits between two databases that cannot share a
transaction, and every interesting failure lives in that gap:

- The webhook is delivered **twice**, because senders retry on purpose.
- The downstream system **accepts the order and the connection drops** before
  the identifier comes back. The order exists there. This side has no idea.
- The downstream system is **down**, and the queue spends its retry budget on
  someone else's outage, turning a one-hour incident into a pile of orders that
  each need a human.
- A worker is **killed mid-dispatch**, and the order it held is never picked up
  again because nothing knows the worker is gone.
- A business rule rejects the order, and the queue **retries the identical
  payload** four more times to get the identical refusal.

Retrying naively creates duplicate sales orders. Not retrying loses paid orders
silently. This engine is the set of mechanisms that make the middle ground
reliable, and a test suite that proves each one.

---

## Architecture

```
  Northwind Market                     ┌───────────────────────────┐
  (fictional storefront)               │   orders                  │
         │                             │   ─────────────────────   │
         │  POST /webhooks/orders      │   status                  │
         │  X-Relay-Signature: HMAC    │   attempts                │
         ▼                             │   next_attempt_at         │
  ┌──────────────┐                     │   lease_token / expires   │
  │ WebhookSig   │ fail-closed         │   erp_reference           │
  │ hash_equals  │                     └───────────┬───────────────┘
  └──────┬───────┘                                 │
         ▼                                         │ FOR UPDATE SKIP LOCKED
  ┌──────────────────┐   UNIQUE(source,event_id)   │ + lease
  │ IngestWebhook    │───────────────┐             │
  │ Event            │               ▼             ▼
  └──────────────────┘        ┌─────────────┐  ┌──────────────┐
                              │ inbox_events│  │ ProcessQueue │
                              │  (dedupe)   │  │   (worker)   │
                              └─────────────┘  └──────┬───────┘
                                                      │
                                               ┌──────▼────────┐
                                               │ DispatchOrder │
                                               └──────┬────────┘
                       UNIQUE(idempotency_key)        │
                    ┌─────────────────────┐           │
                    │ dispatch_operations │◀──────────┤  1. "did I do this?"
                    │  pending → ok|failed│           │  2. "did I do this and
                    └─────────────────────┘           │      lose the answer?"
                                                      ▼
                                             ┌──────────────────┐
                                             │   Atlas ERP      │
                                             │  (fictional)     │
                                             └──────────────────┘
                                                      ▲
   ┌──────────────────────┐   hourly, off the         │
   │ ReconcileDeadLetters │───critical path───────────┘
   └──────────────────────┘   "do you have NW-1001 after all?"
```

Four layers, dependencies pointing inward:

| Layer | Holds | Knows about |
|---|---|---|
| `Domain` | `OrderStatus`, `StateMachine`, `Money` | nothing — no database, no clock |
| `Application` | ingestion, dispatch, queue cycle, reconciliation | `Domain` + interfaces |
| `Infrastructure` | repositories, `ErpGateway` implementations | everything above |
| `Kernel` | PDO, config, logging, clock | nothing above it |

`Application` depends on the `ErpGateway` **interface**, never the HTTP client.
That is what makes "the downstream system times out after committing" a test
case instead of a thought experiment.

---

## Stack

| Choice | Why |
|---|---|
| **PHP 8.2+, no framework** | The subject is the queue. A framework would supply a queue and hide the thing worth showing. One runtime dependency: PHP. |
| **MySQL 8 / SQLite** | The queue lives in the same database as the orders, so a claim and a status change are one transaction. See [ADR-0001](docs/decisions/0001-queue-in-the-database.md). |
| **`FOR UPDATE SKIP LOCKED`** | Workers step over each other's rows instead of queueing behind them. SQLite reaches the same guarantee with `BEGIN IMMEDIATE` — [ADR-0002](docs/decisions/0002-atomic-claim-per-engine.md). |
| **PHPUnit 11** | 75 tests. The integration ones run against real SQL, because a mocked repository proves only that the code calls the methods its author expected. |
| **Composer, dev-only** | Needed for PHPUnit. The engine and the demos run from a bare checkout via `autoload.php`. |

---

## What is implemented

Everything listed here is covered by a test, and most of it by a runnable demo.

**Idempotent ingestion** — Deduplication on `(source, external_event_id)`,
enforced by a `UNIQUE` constraint rather than a check-then-insert. Keyed on the
event, not the order, so `order.created` and `order.paid` are both processed.

**Durable queue with atomic claim** — Claim and status change are one
transaction on one row. Multiple workers in parallel is the normal case.

**Leases, not locks** — A claim expires. A worker killed mid-dispatch strands
nothing: the lease lapses and the order returns to the queue by itself.

**Failure classification** — `unavailable` (dependency down, blameless, costs
no attempt, halts the batch), `rejected` (payload wrong, retrying changes
nothing, goes to a human), `failed` (unknown, retry within budget). Collapsing
these is the most common way a queue misbehaves.

**Exponential backoff with full jitter** — A uniform pick from `[0, window]`,
not `window ± noise`, so a hundred orders that failed together do not return in
one burst and knock the recovering dependency over again.

**Dead letters that are not a graveyard** — `DEAD_LETTER` and `BLOCKED` stop
processing and wait for a person, but they are **not terminal**. Only
`REGISTERED` and `CANCELLED` are. Marking a waiting state terminal makes the
system refuse the exact action the state exists to invite.

**Reconciliation against the system of record** — Before re-sending anything,
ask the downstream system whether it already has the order. This is the only
defence against a lost response, and it runs on its own schedule, off the path
that bills.

**A state machine with a single authority** — Nothing writes `status` directly.
Every change validates against the transition table and writes an audit row in
the same transaction. An audit row written after the commit is the one that goes
missing exactly when the process dies between the two.

**Fail-closed authentication** — HMAC-SHA256 over the raw body, `hash_equals`,
no bypass. An unconfigured secret stops the request; it does not disable the
check.

---

## Demonstration

```bash
php bin/demo.php
```

Six scenarios, each building a throwaway database, driving the real engine
classes, and asserting the outcome. Exits non-zero if any assertion fails, so it
doubles as a smoke test. Time is frozen and advanced explicitly — a backoff
demonstration that waited for real seconds would take an hour.

```
== replay A redelivered webhook does not create a second order
   delivery 1 -> created
   delivery 2 -> duplicate
   delivery 3 -> duplicate
   orders stored: {"ready":1}
   downstream orders: 1
   PASS

== lost-response A lost response does not become a duplicate downstream
   downstream will accept the order, then drop the connection
   cycle 1: {"claimed":1,"halted":"downstream unavailable"}
   local status after the lost response: failed
   downstream orders: 1 (it DID land)
   cycle 2: {"claimed":1,"registered":1}
   final status: registered, downstream id: ATLAS-00001
   PASS

== outage A downstream outage costs no attempts and halts the batch
   5 paid orders queued
   downstream goes down
   cycle: {"claimed":5,"halted":"downstream unavailable"}
   attempts charged per order: 0, 0, 0, 0, 0
   downstream recovers
   cycle: {"claimed":5,"registered":5}
   PASS

== dead-letter An exhausted order is parked, then recovered by reconciliation
   cycle 1 -> status=failed attempts=1
   cycle 2 -> status=failed attempts=2
   cycle 3 -> status=dead_letter attempts=3
   dead_letter is NOT terminal: correct
   downstream turns out to hold the order after all
   reconcile: {"swept":1,"recovered":1,"unavailable":0}
   status after reconciliation: registered (ATLAS-00001)
   PASS

== invalid-transition An illegal state change is refused
   order is ready
   refused: Order NW-5001 cannot move from ready to registered.
   audit trail:
      (new)            -> ready        by webhook
      ready            -> dispatching  by demo
      dispatching      -> registered   by demo
   registered -> dispatching refused: yes
   PASS
```

Run one at a time with `php bin/demo.php outage`.

---

## Running it

Requires PHP 8.2+ with `pdo_sqlite`. Nothing else for the demos.

```bash
git clone https://github.com/moraisxb16/transactional-integration-engine.git
cd transactional-integration-engine

cp .env.example .env          # defaults to SQLite; no credentials needed
php bin/migrate.php
php bin/demo.php
```

**The queue, for real:**

```bash
php bin/worker.php --once         # a single cycle
php bin/worker.php --interval=5   # loop; run several at once, that is the point
php bin/reconcile.php             # the dead-letter sweep (hourly in production)
```

**Against MySQL**, which is the production-shaped path:

```bash
docker compose up -d
# set DB_DRIVER=mysql in .env
php bin/migrate.php
```

**Tests:**

```bash
composer install
composer test                                    # 75 tests
vendor/bin/phpunit --testsuite unit              # fast, no I/O
vendor/bin/phpunit --testsuite integration       # real SQL, real transactions
```

---

## Project structure

```
src/
  Domain/           Order status, state machine, Money. No I/O at all.
  Application/      Ingest, Dispatch, Queue. The use cases.
  Infrastructure/   Repositories, ErpGateway + fake and HTTP implementations.
  Http/             Webhook signature verification.
  Kernel/           PDO, config, logger, clock, wiring.
bin/                migrate, worker, reconcile, demo
database/           migrations, one schema rendered per engine
tests/              Unit (pure) and Integration (real SQLite)
docs/decisions/     ADRs
```

---

## Technical decisions

Each of these has an ADR in [`docs/decisions/`](docs/decisions/):

1. [The queue lives in the database](docs/decisions/0001-queue-in-the-database.md) — not a broker, so a claim and a status change commit together.
2. [The atomic claim differs per engine](docs/decisions/0002-atomic-claim-per-engine.md) — same guarantee, two mechanisms.
3. [Dead letters are reconciled against the system of record](docs/decisions/0003-reconcile-before-resending.md) — never re-send on local state alone.
4. [`dead_letter` and `blocked` are not terminal](docs/decisions/0004-waiting-states-are-not-terminal.md) — the mistake worth not making.
5. [Attempts are charged at claim time](docs/decisions/0005-charge-attempts-at-claim-time.md) — a poison payload cannot retry forever.

---

## Trade-offs and limits

**A permanently unreachable dependency never produces dead letters.** By
design — an outage must not burn attempts. But a misconfigured URL looks exactly
like an infinite outage, and those orders retry forever. The right answer is an
alert on queue age, not a dead letter. Not implemented here.

**`SKIP LOCKED` needs MySQL 8 or PostgreSQL.** On SQLite the claim serializes
through a single write lock. Correct, but it does not scale, and the SQLite path
exists so the demos and tests run anywhere, not for production.

**Ordering is not guaranteed.** Orders are claimed roughly oldest-first and
processed in parallel, so order B can register before order A. For this domain
that is fine. A domain needing per-customer ordering would need a partition key
and one worker per partition.

**There is no HTTP server here.** `WebhookSignature` is the security-relevant
half and is tested; the routing around it is a few lines in any framework and
would not have demonstrated anything.

**The audit trail grows without bound.** Every transition is a row, forever.
Production needs partitioning or a retention policy.

**`last_error` is truncated to 500 characters.** Enough to recognise a failure,
not enough to debug one. The trace goes to the log.

---

## Roadmap

Not implemented. Listed to be explicit about the boundary.

- Alerting on queue age, to cover the permanent-outage gap above
- A circuit breaker, so a down dependency is probed rather than retried
- An operator HTTP API over blocked and dead-lettered orders
- `OpenTelemetry` spans across ingest → claim → dispatch
- A PostgreSQL driver

---

## Independence

This is an independent portfolio project, written from scratch to demonstrate
engineering techniques that are public knowledge: idempotency, atomic claim,
leases, backoff, dead-letter reconciliation, state machines.

It is not connected to, derived from, or a copy of any employer's system. Every
company name in it is invented — **Northwind Market**, **Atlas ERP**,
**Pathfinder Logistics** — and every piece of data is synthetic. No proprietary
business rule, schema, credential or integration contract appears anywhere in
this repository.

MIT licensed. See [LICENSE](LICENSE).

---

<details>
<summary><strong>Resumo em português</strong></summary>

Motor de integração de pedidos em PHP 8, construído em torno de uma pergunta:
**o que acontece quando a chamada deu certo mas a resposta se perdeu?**

Reenviar sem pensar cria pedido de venda duplicado. Não reenviar perde pedido
pago em silêncio. O repositório é o conjunto de mecanismos que tornam esse meio
termo confiável — e os testes que provam cada um:

- **Ingestão idempotente** — deduplicação por `UNIQUE(origem, id_do_evento)`,
  decidida pela constraint, não por um SELECT antes do INSERT
- **Fila no banco com claim atômico** — `FOR UPDATE SKIP LOCKED`, vários workers
  em paralelo, claim e mudança de estado no mesmo commit
- **Lease com expiração** — worker morto não prende pedido: o lease vence e o
  pedido volta sozinho
- **Classificação de falha** — indisponibilidade não consome tentativa e
  interrompe o lote; recusa de regra vai para um humano; erro desconhecido entra
  no orçamento de retentativas
- **Backoff exponencial com jitter total** — sorteio uniforme na janela, para
  cem pedidos não voltarem todos no mesmo instante
- **Dead letter reconciliado contra o destino** — antes de reenviar, pergunta ao
  sistema de registro se ele já tem o pedido
- **`dead_letter` e `blocked` não são terminais** — eles esperam uma pessoa, e
  tratá-los como terminais faz o sistema recusar justamente a ação que o estado
  existe para convidar
- **Autoridade única de transição** — ninguém escreve `status` direto; toda
  mudança valida contra a tabela e grava o evento de auditoria no mesmo commit

`php bin/demo.php` roda seis cenários que provam cada comportamento.
`composer test` roda 75 testes.

Projeto independente, de portfólio, com empresas fictícias e dados sintéticos.
Não é código de nenhum empregador.

</details>
