# 0005 — Attempts are charged when an order is claimed

**Status:** accepted

## Context

`attempts` bounds how often an order is retried before it is parked. The obvious
place to increment it is on failure — that is when an attempt visibly failed.

It leaves a hole. A payload that crashes the worker process, exhausts memory or
hangs it until the lease expires never reaches the failure handler. The counter
never moves, the lease lapses, the order is claimed again, and the worker dies
again. Forever, and taking a worker with it each time.

## Decision

`attempts` is incremented inside the claim, in the same `UPDATE` that sets the
lease. Being picked up counts, regardless of what happens next.

Because an outage must not cost the order anything,
`OrderRepository::returnWithoutPenalty()` decrements it again when work is handed
back unprocessed — the path `ProcessQueue` takes when the downstream system is
unavailable.

## Consequences

**Good.** A poison payload is bounded. Whatever it does to the worker, it has
spent an attempt and reaches `dead_letter` after the budget, where a person sees
it instead of a worker crash-looping unnoticed.

**Bad.** The refund path has to exist and has to be correct. A missed refund
means an outage silently eats the attempt budget — the failure mode this project
takes pains to avoid. It is guarded by
`QueueClaimTest::returning_work_without_penalty_refunds_the_attempt` and
`DeadLetterReconciliationTest::an_outage_never_produces_dead_letters`.

**Visible oddity.** During an outage `attempts` is briefly 1 and then 0 again.
Anyone reading the column mid-cycle sees a number that is about to be undone.
