<?php

declare(strict_types=1);

namespace Relay\Kernel;

use DateTimeImmutable;

/**
 * Time is injected, never read from a global.
 *
 * Every deadline in this engine is a timestamp comparison: a lease expires, a
 * retry becomes due, a dead letter becomes old enough to reconcile. Testing any
 * of that against the real clock means either sleeping or accepting flakiness,
 * and both are worse than passing an interface.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
