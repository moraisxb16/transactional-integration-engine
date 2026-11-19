<?php

declare(strict_types=1);

namespace Relay\Application\Dispatch;

use Relay\Domain\Order\OrderStatus;
use Relay\Infrastructure\Erp\ErpGateway;
use Relay\Infrastructure\Erp\ErpRejected;
use Relay\Infrastructure\Erp\ErpUnavailable;
use Relay\Infrastructure\Persistence\DispatchOperationRepository;
use Relay\Infrastructure\Persistence\OrderRepository;
use Relay\Kernel\Logger;
use Throwable;

/**
 * Sends one claimed order to the downstream system of record.
 *
 * The ordering of the checks below is the design. Before issuing a write that
 * creates money-shaped things, this asks two questions in sequence:
 *
 *   1. "Did I already do this?" — the idempotency ledger.
 *   2. "Did I maybe already do this and lose the answer?" — the downstream
 *      system itself.
 *
 * The second question is the one that is easy to skip and expensive to omit. A
 * reservation left in `pending` means a previous attempt issued the write and
 * never recorded the outcome: a timeout after the order was created. Writing
 * again would create a duplicate sales order, and nothing downstream would
 * flag it. So a pending reservation triggers a lookup, not a retry.
 */
final class DispatchOrder
{
    private const CHANNEL = 'dispatch';
    private const KIND = 'register';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly DispatchOperationRepository $operations,
        private readonly ErpGateway $erp,
        private readonly Logger $logger,
    ) {
    }

    public function dispatch(int $orderId, string $actor = 'worker'): DispatchOutcome
    {
        $order = $this->orders->findById($orderId);
        if ($order === null) {
            return DispatchOutcome::SKIPPED;
        }

        $reference = (string) $order['reference'];
        $status = OrderStatus::from((string) $order['status']);

        // Already finished while this worker held it — a cancellation arriving
        // mid-flight, most often. Nothing to do, and certainly nothing to send.
        if ($status->isTerminal()) {
            return DispatchOutcome::SKIPPED;
        }

        $operation = $this->operations->begin($orderId, self::KIND, $reference);

        // Question 1: a completed reservation means this order was registered on
        // a previous attempt and we simply failed to update its status.
        if ($operation['status'] === DispatchOperationRepository::OK) {
            $downstreamId = (string) ($operation['response']['id'] ?? '');
            if ($downstreamId !== '') {
                $this->orders->markRegistered($orderId, $downstreamId, $actor);

                $this->logger->info(self::CHANNEL, 'Order was already registered; status repaired', [
                    'reference' => $reference,
                    'erp_reference' => $downstreamId,
                ]);

                return DispatchOutcome::ALREADY_REGISTERED;
            }
        }

        // Question 2: a reservation that exists but never completed. We issued a
        // write and lost the answer. Go and look before writing again.
        if (!$operation['fresh'] && $operation['status'] === DispatchOperationRepository::PENDING) {
            $recovered = $this->recoverLostResponse($orderId, $reference, $operation['id'], $actor);
            if ($recovered !== null) {
                return $recovered;
            }
        }

        try {
            $downstreamId = $this->erp->registerOrder(
                $this->toDownstreamPayload($order),
                $this->operations->key(self::KIND, $reference),
            );
        } catch (ErpUnavailable $e) {
            // The reservation stays pending on purpose. It is the marker that
            // says "a write may have landed", and it is what makes the next
            // attempt ask instead of assume.
            $this->logger->warning(self::CHANNEL, 'Downstream unavailable', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return DispatchOutcome::UNAVAILABLE;
        } catch (ErpRejected $e) {
            $this->operations->finish($operation['id'], DispatchOperationRepository::FAILED, [
                'code' => $e->reasonCode,
                'message' => $e->getMessage(),
            ]);

            $this->orders->transition(
                $orderId,
                OrderStatus::BLOCKED,
                $actor,
                'rejected downstream: ' . $e->reasonCode,
                ['message' => $e->getMessage()],
                ['last_error' => mb_substr($e->getMessage(), 0, 500)],
            );

            $this->logger->warning(self::CHANNEL, 'Order blocked by a downstream rule', [
                'reference' => $reference,
                'code' => $e->reasonCode,
            ]);

            return DispatchOutcome::REJECTED;
        } catch (Throwable $e) {
            $this->logger->error(self::CHANNEL, 'Unexpected dispatch failure', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return DispatchOutcome::FAILED;
        }

        $this->operations->finish($operation['id'], DispatchOperationRepository::OK, ['id' => $downstreamId]);
        $this->orders->markRegistered($orderId, $downstreamId, $actor);

        $this->logger->info(self::CHANNEL, 'Order registered downstream', [
            'reference' => $reference,
            'erp_reference' => $downstreamId,
        ]);

        return DispatchOutcome::REGISTERED;
    }

    /**
     * Ask the downstream system whether the order it never acknowledged exists.
     *
     * Returns null when it does not exist there, meaning the caller may go
     * ahead and write. When the lookup ITSELF fails, this returns UNAVAILABLE
     * rather than null: writing while unable to check is exactly the duplicate
     * this whole path exists to prevent.
     */
    private function recoverLostResponse(
        int $orderId,
        string $reference,
        int $operationId,
        string $actor,
    ): ?DispatchOutcome {
        try {
            $downstreamId = $this->erp->findOrderReference($reference);
        } catch (ErpUnavailable $e) {
            $this->logger->warning(self::CHANNEL, 'Cannot verify a pending dispatch; refusing to write again', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return DispatchOutcome::UNAVAILABLE;
        }

        if ($downstreamId === null) {
            $this->logger->info(self::CHANNEL, 'Pending dispatch did not land downstream; safe to retry', [
                'reference' => $reference,
            ]);

            return null;
        }

        $this->operations->finish($operationId, DispatchOperationRepository::OK, ['id' => $downstreamId]);
        $this->orders->markRegistered($orderId, $downstreamId, $actor);

        $this->logger->warning(self::CHANNEL, 'Recovered a lost response: the order existed downstream all along', [
            'reference' => $reference,
            'erp_reference' => $downstreamId,
        ]);

        return DispatchOutcome::ALREADY_REGISTERED;
    }

    /**
     * @param array<string,mixed> $order
     * @return array<string,mixed>
     */
    private function toDownstreamPayload(array $order): array
    {
        return [
            'reference' => (string) $order['reference'],
            'currency' => (string) $order['currency'],
            'total_cents' => (int) $order['total_cents'],
            'customer_ref' => $order['customer_ref'] ?? null,
            'customer_email' => $order['customer_email'] ?? null,
        ];
    }
}
