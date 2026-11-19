<?php

declare(strict_types=1);

namespace Relay\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Relay\Application\Dispatch\DispatchOutcome;
use Relay\Domain\Order\OrderStatus;
use Relay\Tests\IntegrationTestCase;

/**
 * The expensive failure: a write that succeeded downstream and whose response
 * was lost. Every test here is about not creating a second sales order.
 */
final class DispatchIdempotencyTest extends IntegrationTestCase
{
    #[Test]
    public function a_successful_dispatch_registers_exactly_once(): void
    {
        $this->app->ingest()->handle($this->payload('NW-3001', 'evt_1'));
        $claimed = $this->app->orders()->claimBatch('w1', 10, 300);

        $outcome = $this->app->dispatcher()->dispatch($claimed[0]['id']);

        self::assertSame(DispatchOutcome::REGISTERED, $outcome);
        self::assertSame(1, $this->erp->orderCount());

        $order = $this->app->orders()->findByReference('NW-3001');
        self::assertSame(OrderStatus::REGISTERED->value, $order['status']);
        self::assertNotNull($order['erp_reference']);
    }

    /**
     * The headline case. The downstream system creates the order, then the
     * connection drops. A naive retry writes again and the customer is invoiced
     * twice. Here the pending reservation forces a lookup first.
     */
    #[Test]
    public function a_lost_response_does_not_become_a_duplicate(): void
    {
        $this->app->ingest()->handle($this->payload('NW-3101', 'evt_2'));

        $this->erp->loseNextResponse = true;

        $first = $this->app->orders()->claimBatch('w1', 10, 300);
        $outcome = $this->app->dispatcher()->dispatch($first[0]['id']);

        // We saw a failure. Downstream saw a success.
        self::assertSame(DispatchOutcome::UNAVAILABLE, $outcome);
        self::assertSame(1, $this->erp->orderCount(), 'the order DID land downstream');
        self::assertNull($this->app->orders()->findByReference('NW-3101')['erp_reference']);

        // Second attempt: it must ask, not write.
        $this->app->orders()->returnWithoutPenalty([$first[0]['id']], 'w1');
        $this->clock->advance(60);

        $second = $this->app->orders()->claimBatch('w1', 10, 300);
        $outcome = $this->app->dispatcher()->dispatch($second[0]['id']);

        self::assertSame(DispatchOutcome::ALREADY_REGISTERED, $outcome);
        self::assertSame(1, $this->erp->orderCount(), 'still exactly one order downstream');

        $order = $this->app->orders()->findByReference('NW-3101');
        self::assertSame(OrderStatus::REGISTERED->value, $order['status']);
        self::assertNotNull($order['erp_reference']);
    }

    /**
     * If we cannot verify, we must not write. Writing blind while the
     * downstream system is unreachable is precisely the duplicate the pending
     * reservation exists to prevent.
     */
    #[Test]
    public function it_refuses_to_rewrite_while_it_cannot_verify(): void
    {
        $this->app->ingest()->handle($this->payload('NW-3201', 'evt_3'));

        $this->erp->loseNextResponse = true;
        $first = $this->app->orders()->claimBatch('w1', 10, 300);
        $this->app->dispatcher()->dispatch($first[0]['id']);
        self::assertSame(1, $this->erp->orderCount());

        // Now the downstream system is fully down: the lookup fails too.
        $this->erp->goDown();
        $this->app->orders()->returnWithoutPenalty([$first[0]['id']], 'w1');
        $this->clock->advance(60);

        $second = $this->app->orders()->claimBatch('w1', 10, 300);
        $outcome = $this->app->dispatcher()->dispatch($second[0]['id']);

        self::assertSame(DispatchOutcome::UNAVAILABLE, $outcome);
        self::assertSame(1, $this->erp->orderCount(), 'no blind rewrite happened');
    }

    /**
     * The process died between recording the downstream success and updating
     * the order. The ledger already holds the answer, so the next attempt must
     * read it rather than call anyone.
     */
    #[Test]
    public function a_completed_reservation_repairs_the_status_without_calling_downstream(): void
    {
        $this->app->ingest()->handle($this->payload('NW-3301', 'evt_4'));
        $claimed = $this->app->orders()->claimBatch('w1', 10, 300);
        $orderId = $claimed[0]['id'];

        // Reproduce that exact half-finished state by hand.
        $operation = $this->app->operations()->begin($orderId, 'register', 'NW-3301');
        $this->app->operations()->finish($operation['id'], 'ok', ['id' => 'ATLAS-00042']);

        $callsBefore = $this->erp->callCount();
        $outcome = $this->app->dispatcher()->dispatch($orderId);

        self::assertSame(DispatchOutcome::ALREADY_REGISTERED, $outcome);
        self::assertSame($callsBefore, $this->erp->callCount(), 'the ledger answered; nobody was called');

        $order = $this->app->orders()->findByReference('NW-3301');
        self::assertSame(OrderStatus::REGISTERED->value, $order['status']);
        self::assertSame('ATLAS-00042', $order['erp_reference']);
    }

    /**
     * A refusal is not a failure to retry. Retrying an unchanged payload gets
     * the same answer, so the order goes to a human instead of round the loop.
     */
    #[Test]
    public function a_business_refusal_blocks_the_order_instead_of_retrying_it(): void
    {
        $this->app->ingest()->handle($this->payload('NW-3401', 'evt_5'));
        $this->erp->rejectNext('Unknown SKU COFFEE-9', 'unknown_sku');

        $claimed = $this->app->orders()->claimBatch('w1', 10, 300);
        $outcome = $this->app->dispatcher()->dispatch($claimed[0]['id']);

        self::assertSame(DispatchOutcome::REJECTED, $outcome);

        $order = $this->app->orders()->findByReference('NW-3401');
        self::assertSame(OrderStatus::BLOCKED->value, $order['status']);
        self::assertStringContainsString('COFFEE-9', (string) $order['last_error']);

        // Blocked orders are not picked up again on their own.
        self::assertSame([], $this->app->orders()->claimBatch('w1', 10, 300));

        // But a person can fix the data and put it back.
        $this->app->orders()->transition((int) $order['id'], OrderStatus::READY, 'operator:7', 'SKU registered');
        self::assertCount(1, $this->app->orders()->claimBatch('w1', 10, 300));
    }

    #[Test]
    public function a_cancelled_order_is_never_sent(): void
    {
        $this->app->ingest()->handle($this->payload('NW-3501', 'evt_6'));
        $claimed = $this->app->orders()->claimBatch('w1', 10, 300);

        // Cancellation wins the race while the worker holds the claim.
        $this->app->orders()->transition($claimed[0]['id'], OrderStatus::BLOCKED, 'test');
        $this->app->orders()->transition($claimed[0]['id'], OrderStatus::CANCELLED, 'test');

        $outcome = $this->app->dispatcher()->dispatch($claimed[0]['id']);

        self::assertSame(DispatchOutcome::SKIPPED, $outcome);
        self::assertSame(0, $this->erp->orderCount());
    }

    #[Test]
    public function the_idempotency_key_is_deterministic(): void
    {
        $operations = $this->app->operations();

        self::assertSame('register:NW-1', $operations->key('register', 'NW-1'));
        self::assertSame('register:NW-1:txn-2', $operations->key('register', 'NW-1', 'txn-2'));
        self::assertSame(
            $operations->key('register', 'NW-1'),
            $operations->key('register', 'NW-1'),
        );
    }

    /**
     * The suffix exists so operations that legitimately repeat per order get
     * their own key. Without it, a failure partway through a split refund would
     * make the retry refund the first part again.
     */
    #[Test]
    public function suffixed_operations_are_tracked_independently(): void
    {
        $this->app->ingest()->handle($this->payload('NW-3601', 'evt_7'));
        $orderId = (int) $this->app->orders()->findByReference('NW-3601')['id'];

        $operations = $this->app->operations();

        $a = $operations->begin($orderId, 'refund', 'NW-3601', 'txn-1');
        $b = $operations->begin($orderId, 'refund', 'NW-3601', 'txn-2');

        self::assertNotSame($a['id'], $b['id']);
        self::assertTrue($a['fresh']);
        self::assertTrue($b['fresh']);

        $operations->finish($a['id'], 'ok', ['id' => 'R-1']);

        // The first is settled; the second is still outstanding and retryable.
        self::assertFalse($operations->begin($orderId, 'refund', 'NW-3601', 'txn-1')['fresh']);
        self::assertSame('ok', $operations->begin($orderId, 'refund', 'NW-3601', 'txn-1')['status']);
        self::assertSame('pending', $operations->begin($orderId, 'refund', 'NW-3601', 'txn-2')['status']);
    }
}
