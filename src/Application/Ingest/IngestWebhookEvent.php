<?php

declare(strict_types=1);

namespace Relay\Application\Ingest;

use InvalidArgumentException;
use Relay\Domain\Order\OrderStatus;
use Relay\Domain\Shared\Money;
use Relay\Infrastructure\Persistence\InboxRepository;
use Relay\Infrastructure\Persistence\OrderRepository;
use Relay\Kernel\Logger;

/**
 * Turns one webhook delivery into at most one state change.
 *
 * Two independent layers of protection, because they guard different things:
 *
 *  1. The inbox, keyed on the sender's event id, stops the SAME delivery being
 *     processed twice.
 *  2. The UNIQUE constraint on orders.reference stops two DIFFERENT events
 *     creating the same order twice.
 *
 * Either alone leaves a hole. Without the inbox, a redelivered "order.paid"
 * would re-run the promotion. Without the unique constraint, two concurrent
 * events for a new order would both pass the inbox check and both insert.
 *
 * An order is captured REGARDLESS of payment status, so unpaid orders are
 * visible on a dashboard. What payment changes is only the status, and the
 * queue never claims an unpaid one — see OrderStatus::claimable().
 */
final class IngestWebhookEvent
{
    private const CHANNEL = 'ingest';

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly InboxRepository $inbox,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $payload the decoded webhook body
     */
    public function handle(array $payload, string $source = 'northwind'): IngestResult
    {
        $eventId = $this->stringAt($payload, 'event_id');
        $eventType = $this->stringAt($payload, 'event_type');

        if ($eventId === null || $eventType === null) {
            throw new InvalidArgumentException('Webhook payload is missing event_id or event_type.');
        }

        $order = $payload['order'] ?? null;
        if (!is_array($order)) {
            throw new InvalidArgumentException('Webhook payload is missing the order object.');
        }

        $reference = $this->stringAt($order, 'reference');
        if ($reference === null) {
            throw new InvalidArgumentException('Webhook payload is missing order.reference.');
        }

        // Claim the event first. Losing this race means another delivery of the
        // same event got here first, and the only correct action is none.
        if (!$this->inbox->claim($source, $eventId, $eventType, $reference)) {
            $this->logger->info(self::CHANNEL, 'Duplicate delivery ignored', [
                'event_id' => $eventId,
                'reference' => $reference,
            ]);

            return IngestResult::DUPLICATE;
        }

        $result = match ($eventType) {
            'order.created', 'order.paid', 'order.updated' => $this->upsert($order, $reference, $source),
            'order.cancelled' => $this->cancel($reference),
            default => IngestResult::IGNORED,
        };

        $this->inbox->recordOutcome($source, $eventId, $result->value);

        return $result;
    }

    /** @param array<string,mixed> $order */
    private function upsert(array $order, string $reference, string $source): IngestResult
    {
        $paid = strtolower((string) ($order['financial_status'] ?? '')) === 'paid';
        $money = Money::fromDecimalString(
            (string) ($order['total'] ?? '0'),
            strtoupper((string) ($order['currency'] ?? 'USD')),
        );

        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];

        $existing = $this->orders->findByReference($reference);

        if ($existing === null) {
            $status = $paid ? OrderStatus::READY : OrderStatus::PAYMENT_PENDING;

            $id = $this->orders->insertIfAbsent([
                'reference' => $reference,
                'source' => $source,
                'currency' => $money->currency,
                'total_cents' => $money->amountInCents,
                'customer_ref' => $this->stringAt($customer, 'id'),
                'customer_email' => $this->stringAt($customer, 'email'),
                // A digest, never the body. Enough to tell whether a later
                // delivery carried different data, without keeping a copy of
                // every payload that ever arrived.
                'payload_digest' => hash('sha256', json_encode($order) ?: ''),
            ], $status);

            if ($id === null) {
                // Another process inserted it between our read and our write.
                // Fall through and treat it as the known-order case.
                return $this->promoteIfPaid($reference, $paid);
            }

            $this->logger->info(self::CHANNEL, 'Order captured', [
                'reference' => $reference,
                'status' => $status->value,
            ]);

            return IngestResult::CREATED;
        }

        return $this->promoteIfPaid($reference, $paid);
    }

    /**
     * An order we already hold. The only change a later delivery may make is
     * payment_pending -> ready.
     *
     * Everything else is left alone on purpose. Once an order is in the queue,
     * a redelivered payload must not rewrite it: an operator may have corrected
     * a field by hand, and silently overwriting that correction with stale
     * webhook data is the kind of bug nobody reports because nobody sees it.
     */
    private function promoteIfPaid(string $reference, bool $paid): IngestResult
    {
        $order = $this->orders->findByReference($reference);
        if ($order === null) {
            return IngestResult::UNCHANGED;
        }

        $status = OrderStatus::from((string) $order['status']);

        if ($status === OrderStatus::PAYMENT_PENDING && $paid) {
            $this->orders->transition(
                (int) $order['id'],
                OrderStatus::READY,
                'webhook',
                'payment confirmed',
            );

            $this->logger->info(self::CHANNEL, 'Order promoted to ready', ['reference' => $reference]);

            return IngestResult::PROMOTED;
        }

        return IngestResult::UNCHANGED;
    }

    private function cancel(string $reference): IngestResult
    {
        $order = $this->orders->findByReference($reference);
        if ($order === null) {
            return IngestResult::UNCHANGED;
        }

        $status = OrderStatus::from((string) $order['status']);

        // An order already registered downstream is terminal here. Cancelling
        // what has been invoiced is a different business process with its own
        // paperwork, not a status flip in an integration engine.
        if ($status->isTerminal()) {
            $this->logger->info(self::CHANNEL, 'Cancellation ignored for terminal order', [
                'reference' => $reference,
                'status' => $status->value,
            ]);

            return IngestResult::UNCHANGED;
        }

        $this->orders->transition((int) $order['id'], OrderStatus::CANCELLED, 'webhook', 'cancelled upstream');

        return IngestResult::CANCELLED;
    }

    /** @param array<string,mixed> $data */
    private function stringAt(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
