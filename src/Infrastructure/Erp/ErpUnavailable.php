<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Erp;

use RuntimeException;

/**
 * The downstream system could not be reached, or answered that it cannot serve
 * the request right now: connection refused, timeout, 502, 503, no licence free.
 *
 * Nothing was created. The order is blameless, so the worker returns it to the
 * queue WITHOUT charging an attempt and stops the rest of the batch — the next
 * request would hit the same dead host and wait the same timeout.
 */
final class ErpUnavailable extends RuntimeException
{
}
