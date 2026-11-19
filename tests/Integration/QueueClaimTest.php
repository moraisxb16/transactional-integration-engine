<?php

declare(strict_types=1);

namespace Relay\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Relay\Domain\Order\OrderStatus;
use Relay\Tests\IntegrationTestCase;

/**
 * The queue mechanics: claiming, leasing, expiry, and returning work.
 *
 * These run against real SQL in a real transaction. A test double for the
 * repository would assert that the code calls the methods the author expected,
 * which is not the same as asserting that two workers never get the same order.
 */
final class QueueClaimTest extends IntegrationTestCase
{
    #[Test]
    public function a_claim_moves_the_order_and_takes_a_lease(): void
    {
        $this->app->ingest()->handle($this->payload('NW-2001', 'evt_1'));

        $claimed = $this->app->orders()->claimBatch('worker-a', 10, 300);

        self::assertCount(1, $claimed);
        self::assertSame('NW-2001', $claimed[0]['reference']);

        $order = $this->app->orders()->findByReference('NW-2001');
        self::assertSame(OrderStatus::DISPATCHING->value, $order['status']);
        self::assertSame('worker-a', $order['leased_by']);
        self::assertNotNull($order['lease_expires_at']);
        self::assertSame(1, (int) $order['attempts'], 'the attempt is charged at claim time');
    }

    /**
     * The guarantee the whole design rests on. Two workers, one order, and the
     * second must come away with nothing.
     */
    #[Test]
    public function two_workers_never_claim_the_same_order(): void
    {
        foreach (range(1, 6) as $n) {
            $this->app->ingest()->handle($this->payload("NW-21{$n}", "evt_{$n}"));
        }

        $first = $this->app->orders()->claimBatch('worker-a', 3, 300);
        $second = $this->secondWorker()->orders()->claimBatch('worker-b', 10, 300);

        self::assertCount(3, $first);
        self::assertCount(3, $second, 'the second worker gets the remaining three, not the same three');

        $firstIds = array_column($first, 'id');
        $secondIds = array_column($second, 'id');

        self::assertSame([], array_intersect($firstIds, $secondIds), 'no order may be claimed twice');
        self::assertCount(6, array_unique(array_merge($firstIds, $secondIds)));
    }

    #[Test]
    public function a_leased_order_is_invisible_to_other_workers(): void
    {
        $this->app->ingest()->handle($this->payload('NW-2201', 'evt_x'));

        $this->app->orders()->claimBatch('worker-a', 10, 300);
        $second = $this->secondWorker()->orders()->claimBatch('worker-b', 10, 300);

        self::assertSame([], $second);
    }

    /**
     * The recovery path for a worker that died holding a claim. Time-based
     * rather than heartbeat-based, because a heartbeat needs the dead process
     * to cooperate in its own death.
     */
    #[Test]
    public function an_expired_lease_returns_the_order_to_the_queue(): void
    {
        $this->app->ingest()->handle($this->payload('NW-2301', 'evt_y'));
        $this->app->orders()->claimBatch('worker-a', 10, 300);

        // Worker dies here, having done nothing.
        $this->clock->advance(299);
        self::assertSame(0, $this->app->orders()->releaseExpiredLeases(), 'not yet expired');

        $this->clock->advance(2);
        self::assertSame(1, $this->app->orders()->releaseExpiredLeases());

        $order = $this->app->orders()->findByReference('NW-2301');
        self::assertSame(OrderStatus::FAILED->value, $order['status']);
        self::assertNull($order['leased_by']);
        self::assertNull($order['lease_expires_at']);

        // And it is claimable again.
        self::assertCount(1, $this->app->orders()->claimBatch('worker-b', 10, 300));
    }

    /**
     * An outage is not the order's fault. Returning work without charging an
     * attempt is what stops one dependency's bad afternoon from turning the
     * whole queue into dead letters that each need a human.
     */
    #[Test]
    public function returning_work_without_penalty_refunds_the_attempt(): void
    {
        $this->app->ingest()->handle($this->payload('NW-2401', 'evt_z'));

        $claimed = $this->app->orders()->claimBatch('worker-a', 10, 300);
        self::assertSame(1, $this->app->orders()->attemptsOf($claimed[0]['id']));

        $returned = $this->app->orders()->returnWithoutPenalty([$claimed[0]['id']], 'worker-a');

        self::assertSame(1, $returned);
        self::assertSame(0, $this->app->orders()->attemptsOf($claimed[0]['id']));

        $order = $this->app->orders()->findByReference('NW-2401');
        self::assertSame(OrderStatus::FAILED->value, $order['status']);
        self::assertNull($order['leased_by']);
    }

    /** A worker may only hand back what it actually holds. */
    #[Test]
    public function a_worker_cannot_return_another_workers_claim(): void
    {
        $this->app->ingest()->handle($this->payload('NW-2501', 'evt_w'));
        $claimed = $this->app->orders()->claimBatch('worker-a', 10, 300);

        $returned = $this->app->orders()->returnWithoutPenalty([$claimed[0]['id']], 'worker-b');

        self::assertSame(0, $returned);
        self::assertSame('worker-a', $this->app->orders()->findByReference('NW-2501')['leased_by']);
    }

    #[Test]
    public function an_order_waiting_on_backoff_is_not_claimed_early(): void
    {
        $this->app->ingest()->handle($this->payload('NW-2601', 'evt_v'));
        $claimed = $this->app->orders()->claimBatch('worker-a', 10, 300);

        $this->app->orders()->scheduleRetry(
            $claimed[0]['id'],
            $this->clock->now()->modify('+120 seconds'),
            'boom',
        );

        self::assertSame([], $this->app->orders()->claimBatch('worker-a', 10, 300), 'still backing off');

        $this->clock->advance(121);
        self::assertCount(1, $this->app->orders()->claimBatch('worker-a', 10, 300));
    }

    #[Test]
    public function the_batch_size_is_respected(): void
    {
        foreach (range(1, 10) as $n) {
            $this->app->ingest()->handle($this->payload("NW-27{$n}", "evt_b{$n}"));
        }

        self::assertCount(4, $this->app->orders()->claimBatch('worker-a', 4, 300));
    }

    #[Test]
    public function every_claim_is_written_to_the_audit_trail(): void
    {
        $this->app->ingest()->handle($this->payload('NW-2801', 'evt_u'));
        $claimed = $this->app->orders()->claimBatch('worker-a', 10, 300);

        $trail = $this->app->orders()->transitionsOf($claimed[0]['id']);

        self::assertCount(2, $trail);
        self::assertSame('(new)', $trail[0]['from_status']);
        self::assertSame(OrderStatus::READY->value, $trail[0]['to_status']);
        self::assertSame(OrderStatus::DISPATCHING->value, $trail[1]['to_status']);
        self::assertSame('worker:worker-a', $trail[1]['actor']);
    }
}
