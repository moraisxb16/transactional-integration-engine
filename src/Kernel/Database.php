<?php

declare(strict_types=1);

namespace Relay\Kernel;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * PDO factory and transaction helper.
 *
 * Two drivers are supported on purpose, and the difference is not cosmetic: the
 * queue claim relies on row-level locking, which MySQL and SQLite reach by
 * different means. See OrderRepository::claimBatch and
 * docs/decisions/0002-atomic-claim-per-engine.md.
 */
final class Database
{
    public const DRIVER_MYSQL = 'mysql';
    public const DRIVER_SQLITE = 'sqlite';

    private function __construct()
    {
    }

    public static function connect(Config $config): PDO
    {
        $driver = strtolower($config->get('DB_DRIVER', self::DRIVER_SQLITE) ?? self::DRIVER_SQLITE);

        return match ($driver) {
            self::DRIVER_MYSQL => self::connectMysql($config),
            self::DRIVER_SQLITE => self::connectSqlite($config),
            default => throw new RuntimeException("Unsupported DB_DRIVER '{$driver}'. Use 'mysql' or 'sqlite'."),
        };
    }

    private static function connectMysql(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->int('DB_PORT', 3306),
            $config->require('DB_NAME'),
        );

        $pdo = new PDO($dsn, $config->require('DB_USER'), $config->require('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements. Emulation sends the query as text, which
            // defeats the point of binding and breaks LIMIT with an integer bind.
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }

    private static function connectSqlite(Config $config): PDO
    {
        $path = $config->get('DB_SQLITE_PATH', 'var/relay.sqlite') ?? 'var/relay.sqlite';

        if ($path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create database directory '{$directory}'.");
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        // WAL lets a reader run while a writer holds the write lock; without it a
        // worker claiming a batch blocks the HTTP process accepting webhooks.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    public static function driver(PDO $pdo): string
    {
        return (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Run $work inside a transaction, committing on return and rolling back on
     * any throwable.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function transactional(PDO $pdo, callable $work): mixed
    {
        // Nested calls join the outer transaction rather than opening a second
        // one, which PDO would reject anyway. The outermost caller owns the commit.
        if ($pdo->inTransaction()) {
            return $work();
        }

        $pdo->beginTransaction();
        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Is this exception a lost connection rather than a rejected statement?
     *
     * Worth distinguishing: with a dead connection every remaining item in a
     * batch is guaranteed to fail, and the failures cannot even be recorded. The
     * caller stops the batch instead of burning attempts on all of them.
     */
    public static function isLostConnection(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        $message = strtolower($e->getMessage());
        foreach ([
            'server has gone away',
            'lost connection',
            'broken pipe',
            'connection refused',
            'connection reset',
            'no connection to the server',
            'database is locked',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
