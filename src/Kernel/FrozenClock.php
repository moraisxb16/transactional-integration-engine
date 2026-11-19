<?php

declare(strict_types=1);

namespace Relay\Kernel;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Test double. Lives in src/ rather than tests/ on purpose: the demo scripts in
 * bin/ use it to compress hours of backoff into a single run, and a double that
 * only tests can reach would force the demos to sleep for real.
 */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $start = null)
    {
        $this->now = $start ?? new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }

    public function set(DateTimeImmutable $moment): void
    {
        $this->now = $moment;
    }
}
