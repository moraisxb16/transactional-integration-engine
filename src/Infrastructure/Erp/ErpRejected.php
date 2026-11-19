<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Erp;

use RuntimeException;

/**
 * The downstream system was reachable and refused the order: unknown SKU,
 * missing tax classification, a customer that does not exist there.
 *
 * Retrying the identical payload will be refused identically, so this does not
 * go back in the retry loop. It moves the order to BLOCKED, where a person can
 * fix the underlying data and put it back in the queue.
 *
 * $permanent distinguishes "this payload is wrong" from "this payload is wrong
 * for now" — a SKU that is merely not registered yet becomes valid the moment
 * someone registers it, which is exactly the BLOCKED -> READY path.
 *
 * The reason code is called $reasonCode and not $code: Exception already owns
 * $code as a non-readonly int, and redeclaring it readonly is a fatal error.
 */
final class ErpRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'rejected',
        public readonly bool $permanent = false,
    ) {
        parent::__construct($message);
    }
}
