<?php

declare(strict_types=1);

namespace Relay\Domain\Order;

use DomainException;

/**
 * Thrown when something asks for a transition the table does not allow.
 *
 * It carries both ends of the attempt because the useful question in an incident
 * is never "was it rejected" but "what did it think it was doing": an order that
 * tries to go dispatching -> ready points at a different bug than one that tries
 * registered -> dispatching.
 */
final class InvalidTransition extends DomainException
{
    public function __construct(
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly string $reference,
    ) {
        parent::__construct(sprintf(
            'Order %s cannot move from %s to %s.',
            $reference,
            $from->value,
            $to->value,
        ));
    }
}
