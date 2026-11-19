# 0001 — The queue lives in the database, not a broker

**Status:** accepted

## Context

An order arrives, is validated, and later has to be sent to a system of record.
That is queue-shaped work, and the obvious reach is for a broker — Redis, SQS,
RabbitMQ.

But the queue state and the order state are the same state. "This order is being
processed" and "this order is status=dispatching" are one fact. Split across a
broker and a database, that fact can disagree with itself, and the disagreement
is only visible in the cases that matter: an order both in flight and cancelled,
a message acknowledged whose database write rolled back.

## Decision

The queue is columns on the `orders` table: `status`, `attempts`,
`next_attempt_at`, `lease_token`, `lease_expires_at`, `leased_by`.

Claiming an order is a conditional `UPDATE` on the row that already holds the
truth, inside the same transaction as the status change and the audit row.

## Consequences

**Good.** A claim and a status change commit together or not at all. There is no
acknowledgement protocol to get wrong, no dead-letter queue to keep in step with
a dead-letter status, and one place to look when an order is stuck. Operators
query it with SQL they already know.

**Bad.** It does not scale the way a broker does: the claim is a write against a
single table, and past some throughput that table is the bottleneck. There is no
fan-out, no routing, no consumer groups.

**When this would be the wrong call.** High-volume event streaming, multiple
independent consumers of the same event, or anything needing ordering guarantees
across partitions. The moment the queue stops being "work attached to a row this
database owns", a broker is the better tool.
