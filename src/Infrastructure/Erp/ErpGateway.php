<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Erp;

/**
 * The downstream system of record (fictional "Atlas ERP").
 *
 * An interface rather than a concrete client because the queue's behaviour has
 * to be tested against outages, timeouts and duplicate submissions, and none of
 * those are reproducible on demand against a real system. The fake in this
 * namespace is not a shortcut around testing; it is what makes the interesting
 * cases testable at all.
 *
 * The contract draws one distinction that drives everything downstream of it:
 *
 *  - ErpUnavailable means the system is down. Nothing was created, nobody is at
 *    fault, and the right response is to stop the batch rather than send twenty
 *    more requests into a timeout.
 *  - ErpRejected means the system is up and said no. The order is wrong, and
 *    retrying unchanged will be told no again.
 *
 * Collapsing those two into one "it failed" is what produces queues that retry
 * a malformed order five times and give up on an outage after the same five.
 */
interface ErpGateway
{
    /**
     * Create a sales order, returning the downstream identifier.
     *
     * $idempotencyKey is passed through to the downstream system. A well-behaved
     * one returns the SAME identifier for a repeated key instead of creating a
     * second order — which is the only thing that makes a retry after a lost
     * response safe.
     *
     * @param array<string,mixed> $order
     * @throws ErpUnavailable
     * @throws ErpRejected
     */
    public function registerOrder(array $order, string $idempotencyKey): string;

    /**
     * Look up an order by OUR reference, returning the downstream identifier or
     * null when it does not exist there.
     *
     * This is the question reconciliation asks. It exists because a local status
     * is only ever our opinion about what happened downstream, and after a lost
     * response the opinion is worth very little compared to going and looking.
     *
     * @throws ErpUnavailable
     */
    public function findOrderReference(string $reference): ?string;
}
