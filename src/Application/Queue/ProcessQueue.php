<?php

declare(strict_types=1);

namespace Relay\Application\Queue;

use Relay\Application\Dispatch\DispatchOrder;
use Relay\Application\Dispatch\DispatchOutcome;
use Relay\Domain\Order\OrderStatus;
use Relay\Infrastructure\Persistence\OrderRepository;
use Relay\Kernel\Clock;
use Relay\Kernel\Database;
use Relay\Kernel\Logger;
use Throwable;

/**
 * One cycle of the worker: reclaim what expired, claim a batch, dispatch it.
 *
 * The cycle does one job. An earlier design also swept dead letters here, and
 * it was moved out to its own schedule: the sweep re-read the same rows on every
 * cycle, and most of them could never be reconciled anyway, so it spent the
 * dispatch window answering a question whose answer had not changed. Work that
 * is not on the critical path should not run on the critical path's schedule.
 *
 * Two conditions stop a batch early, and both exist because continuing is
 * actively worse than stopping:
 *
 *  - The downstream system is unavailable. Every remaining order would wait the
 *    same timeout for the same refusal. They go back to the queue unpenalised
 *    and the next cycle probes with one order instead of twenty.
 *  - The database connection died. The remaining orders cannot even have their
 *    failures recorded. They are left to the lease expiry, which is designed for
 *    exactly this.
 */
final class ProcessQueue
{
    private const CHANNEL = 'queue';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly DispatchOrder $dispatcher,
        private readonly Backoff $backoff,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly int $batchSize = 20,
        private readonly int $leaseSeconds = 300,
        private readonly int $maxAttempts = 5,
    ) {
    }

    /**
     * @param null|callable(DispatchOutcome):bool $afterEach returning true stops the batch
     * @return array{claimed:int,registered:int,failed:int,blocked:int,dead_lettered:int,released:int,halted:?string}
     */
    public function runCycle(string $workerId, ?callable $afterEach = null): array
    {
        $released = $this->orders->releaseExpiredLeases();
        if ($released > 0) {
            $this->logger->info(self::CHANNEL, 'Expired leases returned to the queue', ['count' => $released]);
        }

        $claimed = $this->orders->claimBatch($workerId, $this->batchSize, $this->leaseSeconds);

        $stats = [
            'claimed' => count($claimed),
            'registered' => 0,
            'failed' => 0,
            'blocked' => 0,
            'dead_lettered' => 0,
            'released' => $released,
            'halted' => null,
        ];

        foreach ($claimed as $index => $item) {
            $orderId = $item['id'];
            $reference = $item['reference'];

            try {
                $outcome = $this->dispatcher->dispatch($orderId, 'worker:' . $workerId);
            } catch (Throwable $e) {
                $this->logger->error(self::CHANNEL, 'Dispatch threw', [
                    'reference' => $reference,
                    'error' => $e->getMessage(),
                ]);

                if (Database::isLostConnection($e)) {
                    $stats['halted'] = 'database connection lost';
                    $this->logger->warning(self::CHANNEL, 'Database connection lost mid-batch; stopping the cycle', [
                        'remaining' => count($claimed) - $index - 1,
                    ]);
                    break;
                }

                $outcome = DispatchOutcome::FAILED;
            }

            switch ($outcome) {
                case DispatchOutcome::REGISTERED:
                case DispatchOutcome::ALREADY_REGISTERED:
                    $stats['registered']++;
                    break;

                case DispatchOutcome::REJECTED:
                    // Already moved to BLOCKED by the dispatcher.
                    $stats['blocked']++;
                    break;

                case DispatchOutcome::UNAVAILABLE:
                    $remaining = array_slice($claimed, $index);
                    $ids = array_map(static fn (array $row): int => $row['id'], $remaining);
                    $returned = $this->orders->returnWithoutPenalty($ids, $workerId);

                    $stats['halted'] = 'downstream unavailable';
                    $this->logger->warning(self::CHANNEL, 'Downstream unavailable; batch halted', [
                        'reference' => $reference,
                        'returned_to_queue' => $returned,
                    ]);
                    break 2;

                case DispatchOutcome::SKIPPED:
                    break;

                case DispatchOutcome::FAILED:
                default:
                    $this->handleFailure($orderId, $reference, $stats);
                    break;
            }

            if ($afterEach !== null && $afterEach($outcome) === true) {
                $this->logger->info(self::CHANNEL, 'Batch stopped by the caller', ['after' => $reference]);
                $stats['halted'] = 'caller requested stop';
                break;
            }
        }

        return $stats;
    }

    /**
     * A transient failure: retry it, or give up on retrying it.
     *
     * The attempt budget is what separates "this will probably work next time"
     * from "something is wrong that retrying will not fix". Exhausting it does
     * not throw the order away — DEAD_LETTER is a waiting room, not a bin, and
     * reconciliation visits it on a schedule.
     *
     * @param array<string,int|string|null> $stats
     */
    private function handleFailure(int $orderId, string $reference, array &$stats): void
    {
        $attempts = $this->orders->attemptsOf($orderId);

        if ($attempts >= $this->maxAttempts) {
            $this->orders->transition(
                $orderId,
                OrderStatus::DEAD_LETTER,
                'worker',
                'attempt budget exhausted',
                ['attempts' => $attempts],
            );

            $stats['dead_lettered']++;
            $this->logger->error(self::CHANNEL, 'Order moved to dead_letter', [
                'reference' => $reference,
                'attempts' => $attempts,
            ]);

            return;
        }

        $nextAttempt = $this->backoff->nextAttemptAt($this->clock->now(), $attempts);
        $this->orders->scheduleRetry($orderId, $nextAttempt, 'dispatch failed');

        $stats['failed']++;
        $this->logger->info(self::CHANNEL, 'Retry scheduled', [
            'reference' => $reference,
            'attempt' => $attempts,
            'next_attempt_at' => $nextAttempt->format(DATE_RFC3339),
        ]);
    }
}
