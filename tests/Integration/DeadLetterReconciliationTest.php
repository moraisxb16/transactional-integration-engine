<?php

declare(strict_types=1);

namespace Relay\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Relay\Domain\Order\OrderStatus;
use Relay\Tests\IntegrationTestCase;

/**
 * Dead letters, and the sweep that gets orders back out of them.
 *
 * The premise worth stating: an order lands in dead_letter because our local
 * bookkeeping says every attempt failed. That is not the same as the order not
 * existing downstream. Reconciliation asks the only system that can actually
 * answer.
 */
final class DeadLetterReconciliationTest extends IntegrationTestCase
{
    #[Test]
    public function an_order_dead_letters_once_its_budget_is_spent(): void
    {
        // QUEUE_MAX_ATTEMPTS is 3 in the base test configuration.
        $this->app->ingest()->handle($this->payload('NW-4001', 'evt_1'));
        $this->erp->breakNext(10);

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->app->queue()->runCycle('w1');
            $this->clock->advance(7200);
        }

        $order = $this->app->orders()->findByReference('NW-4001');
        self::assertSame(OrderStatus::DEAD_LETTER->value, $order['status']);
        self::assertSame(3, (int) $order['attempts']);
    }

    /**
     * An outage must never produce dead letters. The orders did nothing wrong,
     * and a dependency being down for an hour should not leave a pile of work
     * that needs a human each.
     */
    #[Test]
    public function an_outage_never_produces_dead_letters(): void
    {
        foreach (range(1, 4) as $n) {
            $this->app->ingest()->handle($this->payload("NW-41{$n}", "evt_o{$n}"));
        }

        $this->erp->goDown();

        for ($cycle = 0; $cycle < 8; $cycle++) {
            $this->app->queue()->runCycle('w1');
            $this->clock->advance(600);
        }

        $counts = $this->app->orders()->countByStatus();
        self::assertArrayNotHasKey(OrderStatus::DEAD_LETTER->value, $counts);

        $this->erp->comeBackUp();
        $this->clock->advance(600);
        $this->app->queue()->runCycle('w1');

        self::assertSame(4, $this->erp->orderCount());
    }

    #[Test]
    public function a_dead_letter_is_not_claimed_again_automatically(): void
    {
        $this->app->ingest()->handle($this->payload('NW-4201', 'evt_2'));
        $this->erp->breakNext(10);

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->app->queue()->runCycle('w1');
            $this->clock->advance(7200);
        }

        self::assertSame(
            OrderStatus::DEAD_LETTER->value,
            $this->app->orders()->findByReference('NW-4201')['status'],
        );

        $this->clock->advance(86_400);
        self::assertSame([], $this->app->orders()->claimBatch('w1', 10, 300));
    }

    /**
     * The repair. The order was registered downstream and this system never
     * found out; re-sending it would duplicate, leaving it parked means a paid
     * order silently never ships.
     */
    #[Test]
    public function reconciliation_repairs_an_order_that_existed_downstream(): void
    {
        $this->app->ingest()->handle($this->payload('NW-4301', 'evt_3'));
        // Exactly the attempt budget: any extra scripted failure would also hit
        // the out-of-band registration further down.
        $this->erp->breakNext(3);

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->app->queue()->runCycle('w1');
            $this->clock->advance(7200);
        }

        self::assertSame(
            OrderStatus::DEAD_LETTER->value,
            $this->app->orders()->findByReference('NW-4301')['status'],
        );

        // It was there all along.
        $this->erp->registerOrder(['reference' => 'NW-4301'], 'out-of-band');

        $result = $this->app->reconciler()->run();

        self::assertSame(1, $result['swept']);
        self::assertSame(1, $result['recovered']);

        $order = $this->app->orders()->findByReference('NW-4301');
        self::assertSame(OrderStatus::REGISTERED->value, $order['status']);
        self::assertNotNull($order['erp_reference']);
    }

    #[Test]
    public function reconciliation_leaves_orders_that_genuinely_are_not_there(): void
    {
        $this->app->ingest()->handle($this->payload('NW-4401', 'evt_4'));
        $this->erp->breakNext(10);

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->app->queue()->runCycle('w1');
            $this->clock->advance(7200);
        }

        $result = $this->app->reconciler()->run();

        self::assertSame(1, $result['swept']);
        self::assertSame(0, $result['recovered']);
        self::assertSame(
            OrderStatus::DEAD_LETTER->value,
            $this->app->orders()->findByReference('NW-4401')['status'],
        );
    }

    /**
     * One outage means every remaining lookup in the sweep will fail too.
     * Stopping beats producing a page of identical warnings.
     */
    #[Test]
    public function the_sweep_stops_when_the_downstream_system_is_unreachable(): void
    {
        foreach (range(1, 3) as $n) {
            $this->app->ingest()->handle($this->payload("NW-45{$n}", "evt_s{$n}"));
        }
        $this->erp->breakNext(30);

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->app->queue()->runCycle('w1');
            $this->clock->advance(7200);
        }

        $this->erp->goDown();
        $result = $this->app->reconciler()->run();

        self::assertSame(3, $result['swept']);
        self::assertSame(0, $result['recovered']);
        self::assertSame(1, $result['unavailable'], 'it stops after the first failure, not after all three');
    }

    /**
     * dead_letter is a waiting room, not a bin. An operator must be able to act
     * on one — the whole reason it is excluded from the terminal set.
     */
    #[Test]
    public function an_operator_can_retry_a_dead_letter_by_hand(): void
    {
        $this->app->ingest()->handle($this->payload('NW-4601', 'evt_5'));
        $this->erp->breakNext(3);

        for ($cycle = 0; $cycle < 3; $cycle++) {
            $this->app->queue()->runCycle('w1');
            $this->clock->advance(7200);
        }

        $order = $this->app->orders()->findByReference('NW-4601');
        self::assertSame(OrderStatus::DEAD_LETTER->value, $order['status']);

        // The action the state exists to invite.
        $this->app->orders()->transition((int) $order['id'], OrderStatus::DISPATCHING, 'operator:7', 'manual retry');
        $outcome = $this->app->dispatcher()->dispatch((int) $order['id'], 'operator:7');

        self::assertTrue($outcome->isSuccess());
        self::assertSame(
            OrderStatus::REGISTERED->value,
            $this->app->orders()->findByReference('NW-4601')['status'],
        );
    }
}
