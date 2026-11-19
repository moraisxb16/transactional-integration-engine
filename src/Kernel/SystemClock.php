<?php

declare(strict_types=1);

namespace Relay\Kernel;

use DateTimeImmutable;
use DateTimeZone;

/** The real clock. Always UTC: storing local time is how off-by-one-hour bugs start. */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
