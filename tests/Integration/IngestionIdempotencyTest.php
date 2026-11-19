<?php

declare(strict_types=1);

namespace Relay\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use Relay\Application\Ingest\IngestResult;
use Relay\Domain\Order\OrderStatus;
use Relay\Tests\IntegrationTestCase;

final class IngestionIdempotencyTest extends IntegrationTestCase
{
    #[Test]
    public function a_redelivered_event_creates_nothing_the_second_time(): void
    {
        $ingest = $this->app->ingest();
        $payload = $this->payload('NW-1001', 'evt_1');

        self::assertSame(IngestResult::CREATED, $ingest->handle($payload));
        self::assertSame(IngestResult::DUPLICATE, $ingest->handle($payload));
        self::assertSame(IngestResult::DUPLICATE, $ingest->handle($payload));

        self::assertSame(['ready' => 1], $this->app->orders()->countByStatus());
    }

    /**
     * The inbox is keyed on the EVENT id, not the order. One order emits
     * several events over its life and each must be processed once; keying on
     * the order would drop the payment notification as a duplicate of the
     * creation, and the order would sit unpaid forever.
     */
    #[Test]
    public function different_events_for_the_same_order_are_both_processed(): void
    {
        $ingest = $this->app->ingest();

        self::assertSame(
            IngestResult::CREATED,
            $ingest->handle($this->payload('NW-1002', 'evt_created', paid: false)),
        );

        $order = $this->app->orders()->findByReference('NW-1002');
        self::assertSame(OrderStatus::PAYMENT_PENDING->value, $order['status']);

        self::assertSame(
            IngestResult::PROMOTED,
            $ingest->handle($this->payload('NW-1002', 'evt_paid', paid: true)),
        );

        $order = $this->app->orders()->findByReference('NW-1002');
        self::assertSame(OrderStatus::READY->value, $order['status']);
    }

    #[Test]
    public function an_unpaid_order_is_captured_but_never_claimed(): void
    {
        $this->app->ingest()->handle($this->payload('NW-1003', 'evt_2', paid: false));

        // Visible for a dashboard...
        self::assertNotNull($this->app->orders()->findByReference('NW-1003'));

        // ...and invisible to the queue.
        self::assertSame([], $this->app->orders()->claimBatch('w1', 10, 300));
    }

    /**
     * A second webhook must not overwrite an order already being processed. An
     * operator may have corrected a field by hand, and a stale redelivery
     * silently undoing that correction is a bug nobody reports because nobody
     * sees it happen.
     */
    #[Test]
    public function a_later_delivery_does_not_overwrite_an_order_in_flight(): void
    {
        $ingest = $this->app->ingest();
        $ingest->handle($this->payload('NW-1004', 'evt_a'));

        $this->app->orders()->claimBatch('w1', 10, 300);
        $before = $this->app->orders()->findByReference('NW-1004');
        self::assertSame(OrderStatus::DISPATCHING->value, $before['status']);

        $result = $ingest->handle($this->payload('NW-1004', 'evt_b'));

        $after = $this->app->orders()->findByReference('NW-1004');
        self::assertSame(IngestResult::UNCHANGED, $result);
        self::assertSame(OrderStatus::DISPATCHING->value, $after['status']);
        self::assertSame($before['total_cents'], $after['total_cents']);
    }

    #[Test]
    public function a_cancellation_stops_an_order_that_has_not_been_registered(): void
    {
        $ingest = $this->app->ingest();
        $ingest->handle($this->payload('NW-1005', 'evt_c1'));

        $result = $ingest->handle([
            'event_id' => 'evt_c2',
            'event_type' => 'order.cancelled',
            'order' => ['reference' => 'NW-1005'],
        ]);

        self::assertSame(IngestResult::CANCELLED, $result);
        self::assertSame(
            OrderStatus::CANCELLED->value,
            $this->app->orders()->findByReference('NW-1005')['status'],
        );
    }

    /**
     * Once the order exists downstream, cancelling it is a different business
     * process with its own paperwork, not a status flip in an integration engine.
     */
    #[Test]
    public function a_cancellation_is_ignored_once_the_order_is_registered(): void
    {
        $ingest = $this->app->ingest();
        $ingest->handle($this->payload('NW-1006', 'evt_d1'));
        $this->app->queue()->runCycle('w1');

        self::assertSame(
            OrderStatus::REGISTERED->value,
            $this->app->orders()->findByReference('NW-1006')['status'],
        );

        $result = $ingest->handle([
            'event_id' => 'evt_d2',
            'event_type' => 'order.cancelled',
            'order' => ['reference' => 'NW-1006'],
        ]);

        self::assertSame(IngestResult::UNCHANGED, $result);
        self::assertSame(
            OrderStatus::REGISTERED->value,
            $this->app->orders()->findByReference('NW-1006')['status'],
        );
    }

    #[Test]
    public function only_a_digest_of_the_payload_is_stored(): void
    {
        $this->app->ingest()->handle($this->payload('NW-1007', 'evt_e'));

        $order = $this->app->orders()->findByReference('NW-1007');

        // Storing raw bodies is how a disk fills up overnight.
        self::assertSame(64, strlen((string) $order['payload_digest']));
        self::assertArrayNotHasKey('payload', $order);
    }

    #[Test]
    public function an_unknown_event_type_is_recorded_but_changes_nothing(): void
    {
        $result = $this->app->ingest()->handle([
            'event_id' => 'evt_weird',
            'event_type' => 'order.gift_wrapped',
            'order' => ['reference' => 'NW-1008'],
        ]);

        self::assertSame(IngestResult::IGNORED, $result);
        self::assertNull($this->app->orders()->findByReference('NW-1008'));

        // Still recorded, so a redelivery is still recognised as one.
        self::assertNotNull($this->app->inbox()->find('northwind', 'evt_weird'));
    }
}
