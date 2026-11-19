<?php

declare(strict_types=1);

namespace Relay\Domain\Order;

/**
 * Every state an order can hold, in one vocabulary covering both business
 * meaning (cancelled) and processing mechanics (dispatching, failed).
 *
 * One enum rather than a status plus a parallel "processing state" column: two
 * sources of truth for the same question drift, and reconciling them later means
 * deciding which one was lying.
 *
 * TERMINAL IS A NARROW CLUB. Only REGISTERED and CANCELLED. Terminal means the
 * order exists downstream, or it stopped being an order — in both cases touching
 * it again would either duplicate work or act on something void.
 *
 * DEAD_LETTER and BLOCKED are deliberately NOT terminal, and that distinction is
 * the one most often got wrong. Both mean "processing stopped and a person has
 * to look", but nothing reached the downstream system in either case. Treating
 * them as terminal makes the system refuse the very action the state exists to
 * invite: the operator opens the order, fixes the data, presses retry, and is
 * told the order is in a terminal state. Not terminal does not mean "back in the
 * automatic queue" either — that is CLAIMABLE's job, and neither appears there.
 */
enum OrderStatus: string
{
    /** Captured from a webhook. Visible, countable, not yet processable. */
    case RECEIVED = 'received';

    /** Known to us, but the storefront has not confirmed payment. Never claimed. */
    case PAYMENT_PENDING = 'payment_pending';

    /** Payment confirmed. This is the state the queue picks up. */
    case READY = 'ready';

    /** A worker holds a lease on it right now. */
    case DISPATCHING = 'dispatching';

    /** The downstream system of record acknowledged it. Terminal. */
    case REGISTERED = 'registered';

    /** A transient failure. Retried after a backoff, up to the attempt limit. */
    case FAILED = 'failed';

    /** Attempts exhausted. Waits for reconciliation or a human. Not terminal. */
    case DEAD_LETTER = 'dead_letter';

    /** A business rule refused it (unknown SKU, missing tax data). Not terminal. */
    case BLOCKED = 'blocked';

    /** No longer a valid order. Terminal. */
    case CANCELLED = 'cancelled';

    /**
     * States the queue may claim. PAYMENT_PENDING is absent by design: an
     * unpaid order must never be sent downstream. DEAD_LETTER and BLOCKED are
     * absent too — they wait for a decision, and re-queueing them automatically
     * would just burn the same failure on a loop.
     *
     * @return list<self>
     */
    public static function claimable(): array
    {
        return [self::READY, self::FAILED];
    }

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::REGISTERED, self::CANCELLED];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function isClaimable(): bool
    {
        return in_array($this, self::claimable(), true);
    }

    /** True when the order stopped and needs a person, without being terminal. */
    public function awaitsHuman(): bool
    {
        return $this === self::DEAD_LETTER || $this === self::BLOCKED;
    }
}
