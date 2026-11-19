<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Persistence;

use PDO;
use PDOException;
use Relay\Kernel\Clock;

/**
 * Idempotency ledger for outbound writes.
 *
 * The dangerous failure in an integration is not the call that fails. It is the
 * call that SUCCEEDS and whose response is lost — a timeout after the downstream
 * system already committed. Retrying that blindly creates a second sales order,
 * and nothing downstream will tell you it happened.
 *
 * So every outbound write reserves a key here first. A retry finds the
 * reservation and, instead of repeating the write, asks the downstream system
 * what became of the first one.
 */
final class DispatchOperationRepository
{
    public const PENDING = 'pending';
    public const OK = 'ok';
    public const FAILED = 'failed';

    private const DATE_FORMAT = 'Y-m-d H:i:s.v';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Reserve, or return what is already there.
     *
     * $suffix separates operations of the same kind on the same order that
     * genuinely need their own key. A refund split across three payment
     * transactions is three operations; without the suffix, a failure on the
     * second would make the retry refund the first one again.
     *
     * @return array{id:int,status:string,response:array<string,mixed>|null,fresh:bool}
     */
    public function begin(int $orderId, string $kind, string $reference, string $suffix = ''): array
    {
        $key = $this->key($kind, $reference, $suffix);

        $existing = $this->findByKey($key);
        if ($existing !== null) {
            return [
                'id' => (int) $existing['id'],
                'status' => (string) $existing['status'],
                'response' => $existing['response'] !== null
                    ? json_decode((string) $existing['response'], true)
                    : null,
                'fresh' => false,
            ];
        }

        $now = $this->clock->now()->format(self::DATE_FORMAT);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO dispatch_operations
                     (order_id, idempotency_key, kind, status, attempts, created_at, updated_at)
                 VALUES (:order_id, :key, :kind, :status, 0, :created_at, :updated_at)'
            );
            $stmt->execute([
                ':order_id' => $orderId,
                ':key' => $key,
                ':kind' => $kind,
                ':status' => self::PENDING,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            // Another process reserved it between our SELECT and INSERT. Read
            // theirs rather than failing: the point of the key is that exactly
            // one reservation exists, not that we made it.
            if ($this->isUniqueViolation($e)) {
                $existing = $this->findByKey($key);
                if ($existing !== null) {
                    return [
                        'id' => (int) $existing['id'],
                        'status' => (string) $existing['status'],
                        'response' => $existing['response'] !== null
                            ? json_decode((string) $existing['response'], true)
                            : null,
                        'fresh' => false,
                    ];
                }
            }
            throw $e;
        }

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'status' => self::PENDING,
            'response' => null,
            'fresh' => true,
        ];
    }

    /** @param array<string,mixed> $response */
    public function finish(int $operationId, string $status, array $response): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispatch_operations
                SET status = :status, attempts = attempts + 1, response = :response, updated_at = :updated_at
              WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':response' => json_encode($response, JSON_UNESCAPED_UNICODE),
            ':updated_at' => $this->clock->now()->format(self::DATE_FORMAT),
            ':id' => $operationId,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM dispatch_operations WHERE idempotency_key = :key');
        $stmt->execute([':key' => $key]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function findForOrder(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM dispatch_operations WHERE order_id = :order_id ORDER BY id'
        );
        $stmt->execute([':order_id' => $orderId]);

        return $stmt->fetchAll();
    }

    /** Deterministic: the same order and kind always produce the same key. */
    public function key(string $kind, string $reference, string $suffix = ''): string
    {
        return $suffix === ''
            ? $kind . ':' . $reference
            : $kind . ':' . $reference . ':' . $suffix;
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
