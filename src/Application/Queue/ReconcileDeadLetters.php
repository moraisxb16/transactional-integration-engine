<?php

declare(strict_types=1);

namespace Relay\Application\Queue;

use Relay\Infrastructure\Erp\ErpGateway;
use Relay\Infrastructure\Erp\ErpUnavailable;
use Relay\Infrastructure\Persistence\OrderRepository;
use Relay\Kernel\Logger;
use Throwable;

/**
 * Sweeps dead letters and asks the downstream system whether it has them after all.
 *
 * The premise: an order reaches DEAD_LETTER because our local bookkeeping says
 * every attempt failed. But those two facts are not the same thing. A write can
 * succeed downstream and fail on the way back — the order exists there and our
 * status column has no idea. Re-sending it would create a duplicate; leaving it
 * parked means a real, paid order silently never gets fulfilled.
 *
 * So this asks the only authority that can actually answer, and repairs the
 * status when the answer is yes.
 *
 * It runs on its OWN schedule, not inside the worker cycle. It used to live
 * there and was moved out for cost: it re-read the same rows every cycle, most
 * of which could never be reconciled (an order rejected for a bad SKU will never
 * appear downstream no matter how often you ask), and it did so while holding
 * the window that paying orders needed. Hourly is plenty — nothing here is
 * urgent by the time it arrives.
 */
final class ReconcileDeadLetters
{
    private const CHANNEL = 'reconcile';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly ErpGateway $erp,
        private readonly Logger $logger,
    ) {
    }

    /** @return array{swept:int,recovered:int,unavailable:int} */
    public function run(int $limit = 50): array
    {
        $pending = $this->orders->deadLettersToReconcile($limit);

        $recovered = 0;
        $unavailable = 0;

        foreach ($pending as $row) {
            $orderId = (int) $row['id'];
            $reference = (string) $row['reference'];

            try {
                $downstreamId = $this->erp->findOrderReference($reference);
            } catch (ErpUnavailable $e) {
                $unavailable++;
                $this->logger->warning(self::CHANNEL, 'Could not check an order downstream', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);

                // One outage means every remaining lookup in this sweep will
                // fail too. Stop and let the next scheduled run start fresh
                // rather than produce a page of identical warnings.
                break;
            } catch (Throwable $e) {
                $unavailable++;
                $this->logger->error(self::CHANNEL, 'Reconciliation lookup failed', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if ($downstreamId === null) {
                continue;
            }

            $this->orders->markRegistered($orderId, $downstreamId, 'reconciler');
            $recovered++;

            // Logged at error level despite being a success. Recovering one of
            // these means an order was registered downstream and this system
            // did not know for hours — the repair is routine, the fact that it
            // was needed is not, and it should be loud enough to investigate.
            $this->logger->error(self::CHANNEL, 'Dead letter existed downstream all along; status repaired', [
                'reference' => $reference,
                'erp_reference' => $downstreamId,
            ]);
        }

        return [
            'swept' => count($pending),
            'recovered' => $recovered,
            'unavailable' => $unavailable,
        ];
    }
}
