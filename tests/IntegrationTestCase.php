<?php

declare(strict_types=1);

namespace Relay\Tests;

use PHPUnit\Framework\TestCase;
use Relay\Infrastructure\Erp\FakeErp;
use Relay\Kernel\App;
use Relay\Kernel\Config;
use Relay\Kernel\FrozenClock;

/**
 * Base for tests that need a database.
 *
 * Each test gets its own SQLite FILE, not :memory:. In-memory databases are per
 * connection, and these tests exist partly to exercise behaviour across
 * connections; sharing nothing would quietly make the concurrency tests
 * meaningless while still passing.
 *
 * Time is frozen so backoff and lease expiry can be driven forward explicitly
 * instead of waited for.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected App $app;
    protected FrozenClock $clock;
    protected FakeErp $erp;

    private string $databasePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $root = dirname(__DIR__);
        $directory = $root . '/var/tests';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $this->databasePath = $directory . '/' . bin2hex(random_bytes(8)) . '.sqlite';

        $this->clock = new FrozenClock();
        $this->erp = new FakeErp();
        $this->app = new App($this->config(), $root, $this->clock, $this->erp);
        $this->app->migrator()->migrate();
    }

    protected function tearDown(): void
    {
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    protected function config(): Config
    {
        return Config::fromArray(array_merge([
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_PATH' => $this->databasePath,
            'QUEUE_BATCH_SIZE' => '20',
            'QUEUE_LEASE_SECONDS' => '300',
            'QUEUE_MAX_ATTEMPTS' => '3',
            'QUEUE_BACKOFF_BASE_SECONDS' => '10',
            'LOG_LEVEL' => 'silent',
        ], $this->configOverrides()));
    }

    /** @return array<string,string> */
    protected function configOverrides(): array
    {
        return [];
    }

    /**
     * A second App over the SAME database, standing in for another worker
     * process. Separate connection, separate everything but the file.
     */
    protected function secondWorker(): App
    {
        return new App($this->config(), dirname(__DIR__), $this->clock, $this->erp);
    }

    /** @return array<string,mixed> */
    protected function payload(string $reference, string $eventId, bool $paid = true): array
    {
        return [
            'event_id' => $eventId,
            'event_type' => $paid ? 'order.paid' : 'order.created',
            'order' => [
                'reference' => $reference,
                'currency' => 'USD',
                'total' => '100.00',
                'financial_status' => $paid ? 'paid' : 'pending',
                'customer' => ['id' => 'cus_1', 'email' => 'buyer@northwind.invalid'],
            ],
        ];
    }
}
