# 0002 — The atomic claim is implemented differently per engine

**Status:** accepted

## Context

Several workers run at once. The guarantee needed is absolute: no two workers
may ever hold the same order. Without it the same order is sent downstream
twice, and downstream that means two sales orders.

The project supports MySQL (production) and SQLite (demos, tests, and anyone
cloning the repository who should not have to install a database to see it run).
The two engines do not offer the same primitives.

## Decision

One method, `OrderRepository::claimBatch()`, with two implementations behind it.

**MySQL:**

```sql
SELECT id FROM orders
 WHERE status IN ('ready','failed')
   AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
   AND (lease_expires_at IS NULL OR lease_expires_at <= :now)
 ORDER BY next_attempt_at, id
 LIMIT 20
 FOR UPDATE SKIP LOCKED
```

`SKIP LOCKED` steps over rows another worker has locked rather than waiting on
them, so N workers do N times the work instead of queueing behind each other.

**SQLite:** `BEGIN IMMEDIATE` before the select. SQLite has one writer, and
taking the write lock up front serializes claimers.

The subtlety that makes this work: PDO's `beginTransaction()` issues a *deferred*
`BEGIN`, which acquires the write lock only at the first write — too late, as
two readers would already have selected the same rows. The repository issues
`BEGIN IMMEDIATE` through `exec()` instead, and keeps its own commit and
rollback helpers because PDO does not then consider itself in a transaction.

## Consequences

**Good.** The entire test suite and both demos run with no external service,
against the same code paths production uses. Correctness is preserved on both.

**Bad.** Two concurrency models to reason about, and the SQLite one is not
representative of production throughput: claims serialize. The integration tests
prove the *correctness* of the claim, not its *parallelism*.

**Accepted risk.** A MySQL-only bug in the claim would not be caught by the
SQLite suite. Mitigated by keeping the two queries as close as possible — same
predicates, same ordering, same index — so the difference is confined to the
locking clause.
