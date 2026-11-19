<?php

declare(strict_types=1);

namespace Relay\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use PDOException;
use Relay\Domain\Order\InvalidTransition;
use Relay\Domain\Order\OrderStatus;
use Relay\Domain\Order\StateMachine;
use Relay\Kernel\Clock;
use Relay\Kernel\Database;
use Throwable;

/**
 * Persistence for orders, and the single place allowed to change `status`.
 *
 * Nothing else in this codebase writes that column. Every change goes through
 * transition() or through claimBatch(), which is a transition expressed as a
 * conditional UPDATE because it has to be atomic across workers. Both validate
 * against StateMachine and both record the change in order_transitions inside
 * the same transaction as the change itself.
 */
final class OrderRepository
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.v';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Clock $clock,
    ) {
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function findByReference(string $reference): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE reference = :reference');
        $stmt->execute([':reference' => $reference]);

        return $stmt->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function findByStatus(OrderStatus $status, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders WHERE status = :status ORDER BY updated_at LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':status' => $status->value]);

        return $stmt->fetchAll();
    }

    /** @return array<string,int> status => count */
    public function countByStatus(): array
    {
        $rows = $this->pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * Insert an order, or return null when the reference already exists.
     *
     * The null return is not an error path, it is the expected answer to a
     * redelivered webhook. The UNIQUE constraint on `reference` decides it, not
     * a prior SELECT: between a SELECT and an INSERT another process can insert
     * the same order, and under concurrency that race does happen.
     *
     * @param array<string,mixed> $data
     */
    public function insertIfAbsent(array $data, OrderStatus $status): ?int
    {
        $now = $this->clock->now()->format(self::DATE_FORMAT);

        $sql = 'INSERT INTO orders
                    (reference, source, status, currency, total_cents, customer_ref,
                     customer_email, payload_digest, attempts, next_attempt_at,
                     created_at, updated_at)
                VALUES
                    (:reference, :source, :status, :currency, :total_cents, :customer_ref,
                     :customer_email, :payload_digest, 0, :next_attempt_at,
                     :created_at, :updated_at)';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':reference' => $data['reference'],
                ':source' => $data['source'],
                ':status' => $status->value,
                ':currency' => $data['currency'],
                ':total_cents' => $data['total_cents'],
                ':customer_ref' => $data['customer_ref'] ?? null,
                ':customer_email' => $data['customer_email'] ?? null,
                ':payload_digest' => $data['payload_digest'] ?? null,
                ':next_attempt_at' => $status->isClaimable() ? $now : null,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }
            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();
        $this->recordTransition($id, (string) $data['reference'], null, $status, 'webhook', 'captured', []);

        return $id;
    }

    /**
     * The transition authority.
     *
     * Reads the current status under a row lock, validates the move against the
     * table, writes the new status and the audit row, all in one transaction.
     * Locking first is what stops two concurrent callers from both reading
     * `failed` and both deciding they may move to `dispatching`.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extraColumns extra columns to set in the same UPDATE
     * @throws InvalidTransition
     */
    public function transition(
        int $id,
        OrderStatus $to,
        string $actor,
        ?string $reason = null,
        array $context = [],
        array $extraColumns = [],
    ): bool {
        return Database::transactional($this->pdo, function () use ($id, $to, $actor, $reason, $context, $extraColumns): bool {
            $locking = Database::driver($this->pdo) === Database::DRIVER_MYSQL ? ' FOR UPDATE' : '';
            $stmt = $this->pdo->prepare('SELECT reference, status FROM orders WHERE id = :id' . $locking);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();

            if ($row === false) {
                return false;
            }

            $reference = (string) $row['reference'];
            $from = OrderStatus::from((string) $row['status']);

            // Idempotent by design: asking for the state an order is already in
            // is a no-op, not a failure. A retried operator click and a
            // redelivered callback both produce exactly that request.
            if ($from === $to) {
                return true;
            }

            StateMachine::assert($from, $to, $reference);

            $now = $this->clock->now()->format(self::DATE_FORMAT);
            $sets = ['status = :status', 'updated_at = :updated_at'];
            $params = [':status' => $to->value, ':updated_at' => $now, ':id' => $id];

            foreach ($extraColumns as $column => $value) {
                $sets[] = $column . ' = :extra_' . $column;
                $params[':extra_' . $column] = $value;
            }

            // A lease only means anything while an order is DISPATCHING, so
            // every move out of that state clears it.
            //
            // This used to clear the lease only for terminal and human-held
            // states, and the omission was a real bug: an order scheduled for
            // retry kept its old lease, so the claim query skipped it until the
            // LEASE expired rather than until the BACKOFF did. With a 300s lease
            // and a 10s backoff that is a five-minute stall on every first
            // retry, invisible except as a queue that is mysteriously slow.
            // QueueClaimTest::an_order_waiting_on_backoff_is_not_claimed_early
            // is what caught it.
            if ($to !== OrderStatus::DISPATCHING) {
                $sets[] = 'lease_token = NULL';
                $sets[] = 'lease_expires_at = NULL';
                $sets[] = 'leased_by = NULL';
            }

            $update = $this->pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $update->execute($params);

            $this->recordTransition($id, $reference, $from, $to, $actor, $reason, $context);

            return true;
        });
    }

    // -----------------------------------------------------------------------
    // The queue
    // -----------------------------------------------------------------------

    /**
     * Claim up to $limit orders for this worker, atomically.
     *
     * The guarantee needed is that no two workers ever hold the same order. The
     * two engines reach it differently, and the difference is real rather than
     * cosmetic:
     *
     *  - MySQL: SELECT ... FOR UPDATE SKIP LOCKED. Rows another worker already
     *    locked are stepped over instead of waited on, so N workers do N times
     *    the work rather than queueing behind each other.
     *  - SQLite: BEGIN IMMEDIATE takes the single write lock up front, which
     *    serializes claimers. Correct, and adequate at the scale SQLite is for.
     *
     * A claim is not a fact about the world, it is a lease with an expiry. If
     * this process is killed between claiming and dispatching, nothing has to
     * notice: lease_expires_at passes and the row becomes claimable again.
     *
     * @return list<array{id:int,reference:string,lease_token:string}>
     */
    public function claimBatch(string $workerId, int $limit, int $leaseSeconds): array
    {
        $isMysql = Database::driver($this->pdo) === Database::DRIVER_MYSQL;

        if ($isMysql) {
            $this->pdo->beginTransaction();
        } else {
            // PDO's beginTransaction() issues a deferred BEGIN, which takes the
            // write lock only at the first write. That is too late: two readers
            // would already have selected the same rows.
            $this->pdo->exec('BEGIN IMMEDIATE');
        }

        try {
            $now = $this->clock->now();
            $nowString = $now->format(self::DATE_FORMAT);

            $claimable = array_map(
                static fn (OrderStatus $s): string => "'" . $s->value . "'",
                OrderStatus::claimable(),
            );

            $select = sprintf(
                'SELECT id, reference, status FROM orders
                  WHERE status IN (%s)
                    AND (next_attempt_at IS NULL OR next_attempt_at <= :now)
                    AND (lease_expires_at IS NULL OR lease_expires_at <= :now)
                  ORDER BY next_attempt_at, id
                  LIMIT %d%s',
                implode(',', $claimable),
                max(1, $limit),
                $isMysql ? ' FOR UPDATE SKIP LOCKED' : '',
            );

            $stmt = $this->pdo->prepare($select);
            $stmt->execute([':now' => $nowString]);
            $rows = $stmt->fetchAll();

            if ($rows === []) {
                $this->commit();

                return [];
            }

            $token = bin2hex(random_bytes(16));
            $expiresAt = $now->modify('+' . $leaseSeconds . ' seconds')->format(self::DATE_FORMAT);
            $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            // attempts is incremented HERE, at claim time, not on failure. A
            // worker killed mid-dispatch would otherwise cost nothing, and a
            // payload that reliably kills workers would be retried forever.
            $update = $this->pdo->prepare(
                'UPDATE orders
                    SET status = ?, lease_token = ?, lease_expires_at = ?, leased_by = ?,
                        attempts = attempts + 1, updated_at = ?
                  WHERE id IN (' . $placeholders . ')'
            );
            $update->execute(array_merge(
                [OrderStatus::DISPATCHING->value, $token, $expiresAt, $workerId, $nowString],
                $ids,
            ));

            foreach ($rows as $row) {
                $this->recordTransition(
                    (int) $row['id'],
                    (string) $row['reference'],
                    OrderStatus::from((string) $row['status']),
                    OrderStatus::DISPATCHING,
                    'worker:' . $workerId,
                    'claimed',
                    ['lease_expires_at' => $expiresAt],
                );
            }

            $this->commit();

            return array_map(
                static fn (array $r): array => [
                    'id' => (int) $r['id'],
                    'reference' => (string) $r['reference'],
                    'lease_token' => $token,
                ],
                $rows,
            );
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Move orders whose lease expired back to a claimable state.
     *
     * This is the only recovery path for a worker that died without releasing
     * its claim, and it is deliberately time-based rather than heartbeat-based:
     * a heartbeat needs the dead process to cooperate in its own death.
     */
    public function releaseExpiredLeases(): int
    {
        $now = $this->clock->now()->format(self::DATE_FORMAT);

        return (int) Database::transactional($this->pdo, function () use ($now): int {
            $stmt = $this->pdo->prepare(
                'SELECT id, reference FROM orders
                  WHERE status = :dispatching
                    AND lease_expires_at IS NOT NULL
                    AND lease_expires_at <= :now'
            );
            $stmt->execute([':dispatching' => OrderStatus::DISPATCHING->value, ':now' => $now]);
            $rows = $stmt->fetchAll();

            foreach ($rows as $row) {
                $update = $this->pdo->prepare(
                    'UPDATE orders
                        SET status = :failed, lease_token = NULL, lease_expires_at = NULL,
                            leased_by = NULL, next_attempt_at = :now, updated_at = :now
                      WHERE id = :id'
                );
                $update->execute([
                    ':failed' => OrderStatus::FAILED->value,
                    ':now' => $now,
                    ':id' => (int) $row['id'],
                ]);

                $this->recordTransition(
                    (int) $row['id'],
                    (string) $row['reference'],
                    OrderStatus::DISPATCHING,
                    OrderStatus::FAILED,
                    'lease-reaper',
                    'lease expired',
                    [],
                );
            }

            return count($rows);
        });
    }

    /**
     * Hand claimed orders back without charging them an attempt.
     *
     * Used when the downstream system is down. Those orders did nothing wrong,
     * and burning their attempt budget on an outage is how one dependency's bad
     * afternoon becomes a pile of dead letters that each need a human.
     *
     * @param list<int> $ids
     */
    public function returnWithoutPenalty(array $ids, string $workerId): int
    {
        if ($ids === []) {
            return 0;
        }

        $now = $this->clock->now()->format(self::DATE_FORMAT);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $this->pdo->prepare(
            'UPDATE orders
                SET status = ?,
                    attempts = CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END,
                    lease_token = NULL, lease_expires_at = NULL, leased_by = NULL,
                    next_attempt_at = ?, updated_at = ?
              WHERE id IN (' . $placeholders . ') AND leased_by = ? AND status = ?'
        );
        $stmt->execute(array_merge(
            [OrderStatus::FAILED->value, $now, $now],
            $ids,
            [$workerId, OrderStatus::DISPATCHING->value],
        ));

        return $stmt->rowCount();
    }

    /** Schedule the next attempt for an order that failed transiently. */
    public function scheduleRetry(int $id, DateTimeImmutable $nextAttemptAt, string $error): bool
    {
        $next = $nextAttemptAt->format(self::DATE_FORMAT);

        return $this->transition(
            $id,
            OrderStatus::FAILED,
            'worker',
            'transient failure',
            ['next_attempt_at' => $next],
            [
                'next_attempt_at' => $next,
                // Truncated on purpose: this column is for recognising a
                // failure, not for storing a stack trace. The trace goes to the log.
                'last_error' => mb_substr($error, 0, 500),
            ],
        );
    }

    public function markRegistered(int $id, string $erpReference, string $actor): bool
    {
        return $this->transition(
            $id,
            OrderStatus::REGISTERED,
            $actor,
            'accepted downstream',
            ['erp_reference' => $erpReference],
            ['erp_reference' => $erpReference, 'last_error' => null],
        );
    }

    public function attemptsOf(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT attempts FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string,mixed>> */
    public function deadLettersToReconcile(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reference FROM orders
              WHERE status = :status AND erp_reference IS NULL
              ORDER BY updated_at
              LIMIT ' . max(1, $limit)
        );
        $stmt->execute([':status' => OrderStatus::DEAD_LETTER->value]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function transitionsOf(int $orderId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT from_status, to_status, actor, reason, created_at
               FROM order_transitions WHERE order_id = :id ORDER BY id'
        );
        $stmt->execute([':id' => $orderId]);

        return $stmt->fetchAll();
    }

    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $context */
    private function recordTransition(
        int $orderId,
        string $reference,
        ?OrderStatus $from,
        OrderStatus $to,
        string $actor,
        ?string $reason,
        array $context,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_transitions
                 (order_id, order_reference, from_status, to_status, actor, reason, context, created_at)
             VALUES (:order_id, :reference, :from_status, :to_status, :actor, :reason, :context, :created_at)'
        );
        $stmt->execute([
            ':order_id' => $orderId,
            ':reference' => $reference,
            ':from_status' => $from?->value ?? '(new)',
            ':to_status' => $to->value,
            ':actor' => $actor,
            ':reason' => $reason,
            ':context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE),
            ':created_at' => $this->clock->now()->format(self::DATE_FORMAT),
        ]);
    }

    private function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        } else {
            // SQLite's BEGIN IMMEDIATE was issued through exec(), so PDO does
            // not consider itself in a transaction and commit() would throw.
            $this->pdo->exec('COMMIT');
        }
    }

    private function rollback(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec('ROLLBACK');
            }
        } catch (Throwable) {
            // Already rolled back, or the connection is gone. Either way the
            // original exception is the one worth propagating.
        }
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
