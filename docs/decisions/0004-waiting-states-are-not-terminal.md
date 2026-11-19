# 0004 — `dead_letter` and `blocked` are not terminal

**Status:** accepted

## Context

An order stops for one of two reasons: it finished, or it needs a person.

The tempting model groups them. Both are "not moving any more", so both look
terminal, and terminal states are convenient — you can guard every mutation with
one check.

## Decision

Terminal means exactly two things: `REGISTERED` (the order exists in the system
of record) or `CANCELLED` (it stopped being an order). Nothing else.

`DEAD_LETTER` and `BLOCKED` stop automatic processing and wait for a human. They
are **not** terminal, and `StateMachine` gives both outgoing transitions.

Not terminal is also not "back in the queue". `OrderStatus::claimable()` is a
separate list containing only `READY` and `FAILED`, so neither is picked up
automatically. They wait — the difference is that the waiting can end.

## Consequences

**Good.** The states work. An operator opens a blocked order, registers the
missing SKU, and moves it back to `READY`. A dead letter can be retried by hand
or repaired by reconciliation.

**What grouping them would have cost.** Terminal is used to guard mutation:
editing is refused, holds are refused, "retry now" is refused. Mark a waiting
state terminal and the system refuses *the exact action the state exists to
invite* — the order is simultaneously asking for human intervention and
rejecting it. Worse, the symptom is an error message that sounds correct
("Order is in a terminal state"), so it gets reported as confusing rather than
as a bug.

**Cost.** Two concepts to keep straight: terminal, and claimable. Enforced by
`StateMachineTest::states_awaiting_a_human_are_not_terminal` and
`StateMachineTest::the_table_has_no_dead_ends`.
