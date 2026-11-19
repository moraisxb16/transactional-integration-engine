<?php

declare(strict_types=1);

namespace Relay\Application\Dispatch;

/**
 * How one dispatch attempt ended.
 *
 * The three failure cases are kept apart because the engine must respond to
 * each one differently, and collapsing them is the single most common way a
 * queue ends up misbehaving:
 *
 *  - UNAVAILABLE: the dependency is down. The order is blameless. Do not spend
 *    an attempt, and stop the batch.
 *  - REJECTED: the payload is wrong. Retrying changes nothing. Park it for a
 *    person.
 *  - FAILED: something else broke. Retry it later, with a budget.
 */
enum DispatchOutcome: string
{
    /** Accepted downstream. The order now has an identifier there. */
    case REGISTERED = 'registered';

    /**
     * It was already there — discovered through the idempotency ledger or by
     * asking. No second order was created, which is the whole point.
     */
    case ALREADY_REGISTERED = 'already_registered';

    /** The downstream system is unreachable. Nothing was created. */
    case UNAVAILABLE = 'unavailable';

    /** The downstream system refused it. A person has to fix the data. */
    case REJECTED = 'rejected';

    /** An unexpected error. Eligible for retry within the attempt budget. */
    case FAILED = 'failed';

    /** The order was not in a state that could be dispatched. */
    case SKIPPED = 'skipped';

    public function isSuccess(): bool
    {
        return $this === self::REGISTERED || $this === self::ALREADY_REGISTERED;
    }

    /** Should this outcome consume one of the order's attempts? */
    public function consumesAttempt(): bool
    {
        return $this === self::FAILED;
    }
}
