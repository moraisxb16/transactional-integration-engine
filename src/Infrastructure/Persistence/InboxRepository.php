<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Persistence;

use PDO;
use PDOException;
use Relay\Kernel\Clock;

/**
 * The inbox: one row per webhook delivery, keyed by the sender's event id.
 *
 * This is where exactly-once processing is actually enforced. Everything else in
 * the ingestion path can be retried safely because this table refuses the second
 * attempt to record the same event.
 *
 * Keyed on the EVENT id, not the order reference: one order legitimately emits
 * several events over its life (created, paid, cancelled) and each of those has
 * to be processed once. Keying on the order would drop the payment notification
 * as a duplicate of the creation.
 */
final class InboxRepository
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.v';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Try to claim an event for processing.
     *
     * Returns true when this caller is the first to see it and should proceed.
     * Returns false when the event was already recorded, which means a previous
     * delivery either processed it or is processing it right now.
     *
     * The decision is made by the INSERT hitting a UNIQUE constraint, never by a
     * preceding SELECT. A check-then-act pair has a window between the two, and
     * webhook senders retry fast enough to find it.
     */
    public function claim(string $source, string $externalEventId, string $eventType, ?string $orderReference): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO inbox_events (source, external_event_id, event_type, order_reference, received_at)
                 VALUES (:source, :event_id, :event_type, :order_reference, :received_at)'
            );
            $stmt->execute([
                ':source' => $source,
                ':event_id' => $externalEventId,
                ':event_type' => $eventType,
                ':order_reference' => $orderReference,
                ':received_at' => $this->clock->now()->format(self::DATE_FORMAT),
            ]);

            return true;
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Record what the first processing decided.
     *
     * Stored so a redelivery can be answered with the same outcome instead of a
     * bare "duplicate": the sender asked a question and deserves the original
     * answer, not a shrug.
     */
    public function recordOutcome(string $source, string $externalEventId, string $outcome): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE inbox_events SET outcome = :outcome
              WHERE source = :source AND external_event_id = :event_id'
        );
        $stmt->execute([
            ':outcome' => $outcome,
            ':source' => $source,
            ':event_id' => $externalEventId,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $source, string $externalEventId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM inbox_events WHERE source = :source AND external_event_id = :event_id'
        );
        $stmt->execute([':source' => $source, ':event_id' => $externalEventId]);

        return $stmt->fetch() ?: null;
    }

    public function countForOrder(string $orderReference): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM inbox_events WHERE order_reference = :reference');
        $stmt->execute([':reference' => $orderReference]);

        return (int) $stmt->fetchColumn();
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        if ($e->getCode() === '23000' || $e->getCode() === '23505') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }
}
