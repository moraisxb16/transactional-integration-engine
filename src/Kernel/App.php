<?php

declare(strict_types=1);

namespace Relay\Kernel;

use PDO;
use Relay\Application\Dispatch\DispatchOrder;
use Relay\Application\Ingest\IngestWebhookEvent;
use Relay\Application\Queue\Backoff;
use Relay\Application\Queue\ProcessQueue;
use Relay\Application\Queue\ReconcileDeadLetters;
use Relay\Infrastructure\Erp\ErpGateway;
use Relay\Infrastructure\Erp\FakeErp;
use Relay\Infrastructure\Erp\HttpErpClient;
use Relay\Infrastructure\Persistence\DispatchOperationRepository;
use Relay\Infrastructure\Persistence\InboxRepository;
use Relay\Infrastructure\Persistence\Migrator;
use Relay\Infrastructure\Persistence\OrderRepository;

/**
 * Wiring, in one readable place.
 *
 * Hand-written rather than a DI container: this object graph is a dozen nodes
 * deep and fits on a screen, and a reader who wants to know what talks to what
 * can find out here instead of inferring it from annotations. A container earns
 * its complexity at a scale this project does not reach.
 *
 * Everything is lazily built and memoised, so asking for a repository twice
 * gives the same instance — which matters for the FakeErp, whose entire purpose
 * is to accumulate state across calls.
 */
final class App
{
    private ?PDO $pdo = null;
    private ?Clock $clock = null;
    private ?Logger $logger = null;
    private ?ErpGateway $erp = null;
    private ?OrderRepository $orders = null;
    private ?InboxRepository $inbox = null;
    private ?DispatchOperationRepository $operations = null;

    public function __construct(
        private readonly Config $config,
        private readonly string $projectRoot,
        ?Clock $clock = null,
        ?ErpGateway $erp = null,
    ) {
        $this->clock = $clock;
        $this->erp = $erp;
    }

    public static function boot(?string $projectRoot = null): self
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);

        return new self(Config::fromEnvironment($root . '/.env'), $root);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function clock(): Clock
    {
        return $this->clock ??= new SystemClock();
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger(
            $this->clock(),
            null,
            $this->config->get('LOG_LEVEL', Logger::INFO) ?? Logger::INFO,
        );
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= Database::connect($this->config);
    }

    public function migrator(): Migrator
    {
        return new Migrator($this->pdo(), $this->projectRoot . '/database/migrations');
    }

    public function orders(): OrderRepository
    {
        return $this->orders ??= new OrderRepository($this->pdo(), $this->clock());
    }

    public function inbox(): InboxRepository
    {
        return $this->inbox ??= new InboxRepository($this->pdo(), $this->clock());
    }

    public function operations(): DispatchOperationRepository
    {
        return $this->operations ??= new DispatchOperationRepository($this->pdo(), $this->clock());
    }

    public function erp(): ErpGateway
    {
        if ($this->erp !== null) {
            return $this->erp;
        }

        $driver = strtolower($this->config->get('ERP_DRIVER', 'fake') ?? 'fake');

        return $this->erp = $driver === 'http'
            ? new HttpErpClient(
                $this->config->require('ERP_BASE_URL'),
                $this->config->require('ERP_API_KEY'),
                $this->logger(),
                $this->config->int('ERP_TIMEOUT_SECONDS', 30),
            )
            : new FakeErp();
    }

    public function ingest(): IngestWebhookEvent
    {
        return new IngestWebhookEvent($this->orders(), $this->inbox(), $this->logger());
    }

    public function dispatcher(): DispatchOrder
    {
        return new DispatchOrder($this->orders(), $this->operations(), $this->erp(), $this->logger());
    }

    public function backoff(): Backoff
    {
        return new Backoff(
            $this->config->int('QUEUE_BACKOFF_BASE_SECONDS', 10),
            $this->config->int('QUEUE_BACKOFF_MAX_SECONDS', 3600),
        );
    }

    public function queue(): ProcessQueue
    {
        return new ProcessQueue(
            $this->orders(),
            $this->dispatcher(),
            $this->backoff(),
            $this->clock(),
            $this->logger(),
            $this->config->int('QUEUE_BATCH_SIZE', 20),
            $this->config->int('QUEUE_LEASE_SECONDS', 300),
            $this->config->int('QUEUE_MAX_ATTEMPTS', 5),
        );
    }

    public function reconciler(): ReconcileDeadLetters
    {
        return new ReconcileDeadLetters($this->orders(), $this->erp(), $this->logger());
    }
}
