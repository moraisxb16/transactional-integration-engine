<?php

declare(strict_types=1);

namespace Relay\Application\Queue;

use DateTimeImmutable;

/**
 * Exponential backoff with full jitter.
 *
 * Exponential because a dependency that just failed is usually still failing a
 * second later, and hammering it is how a brief wobble becomes an outage.
 *
 * Jittered because without it every order that failed during the same incident
 * retries at the same instant. The downstream system recovers, gets the entire
 * backlog in one burst, falls over again, and the engine has built itself a
 * self-sustaining outage. "Full jitter" — a uniform pick from [0, window] rather
 * than window +/- a bit — spreads the retries across the whole interval, which
 * is the variant that measures best for exactly this case.
 *
 * The cost is that a retry can be scheduled sooner than the previous one. That
 * is fine: the attempt counter, not the delay, is what bounds the work.
 */
final class Backoff
{
    public function __construct(
        private readonly int $baseSeconds = 10,
        private readonly int $maxSeconds = 3600,
        /** Injectable so tests can assert the window instead of chasing a random number. */
        private readonly ?\Closure $randomizer = null,
    ) {
    }

    /**
     * Delay in seconds before attempt number $attempt is retried.
     *
     * $attempt is 1-based: the first failure produces attempt 1, whose window is
     * [0, base].
     */
    public function delayFor(int $attempt): int
    {
        $window = $this->windowFor($attempt);

        $pick = $this->randomizer ?? static fn (int $min, int $max): int => random_int($min, $max);

        return ($pick)(0, $window);
    }

    /** The upper bound of the jitter window, before the random pick. */
    public function windowFor(int $attempt): int
    {
        $attempt = max(1, $attempt);

        // Cap the exponent before computing the power: 2**64 overflows into a
        // float and the cap stops meaning anything.
        $exponent = min($attempt - 1, 30);
        $window = $this->baseSeconds * (2 ** $exponent);

        return (int) min($window, $this->maxSeconds);
    }

    public function nextAttemptAt(DateTimeImmutable $now, int $attempt): DateTimeImmutable
    {
        return $now->modify('+' . $this->delayFor($attempt) . ' seconds');
    }
}
