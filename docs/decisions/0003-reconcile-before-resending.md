# 0003 — Never re-send on local state alone

**Status:** accepted

## Context

The expensive failure in an integration is not the call that fails. It is the
call that **succeeds and whose response is lost**: a timeout after the
downstream system already committed.

Locally that is indistinguishable from a call that never landed. The attempt
counter goes up, the status goes to `failed`, and eventually `dead_letter`. The
order exists downstream and this system believes it does not.

Both naive responses are wrong. Re-send, and there are two sales orders for one
purchase — and nothing downstream flags it. Leave it parked, and a paid order
silently never ships.

## Decision

Two layers.

**An idempotency ledger.** Every outbound write reserves a deterministic key
(`register:NW-1001`) in `dispatch_operations` *before* the call. A reservation
left in `pending` is the marker that says "a write may have landed".

**A lookup before any rewrite.** When `DispatchOrder` finds a pending
reservation it does not retry the write. It calls
`ErpGateway::findOrderReference()` and asks. Found: record the identifier and
repair the status. Not found: safe to write. **Lookup itself fails: do nothing** —
writing while unable to verify is exactly the duplicate this prevents.

`ReconcileDeadLetters` applies the same question on a schedule to everything
already parked.

The key takes an optional suffix for operations that legitimately repeat per
order. A refund split across three payment transactions is three keys; without
the suffix, a failure on the second would make the retry refund the first again.

## Consequences

**Good.** A lost response is survivable. Dead letters drain on their own when
the cause was transport rather than data. The audit trail shows exactly when a
status was repaired and by whom.

**Bad.** It requires the downstream system to support lookup by *our* reference.
Not all do. Where it is unavailable this degrades to the idempotency key alone,
which only helps if the downstream system honours it.

**Cost.** An extra round trip on every retry that follows a lost response. Cheap
compared to one duplicate invoice.

**Noise, on purpose.** A successful recovery is logged at `error` level. The
repair is routine; the fact that it was needed means an order was registered and
unknown here for hours, and that should be loud enough to investigate.
