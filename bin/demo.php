<?php

declare(strict_types=1);

/**
 * Runnable proof of the five behaviours this engine exists to provide.
 *
 * Usage:
 *   php bin/demo.php            every scenario
 *   php bin/demo.php replay     one of: replay, lost-response, outage,
 *                               dead-letter, invalid-transition, backoff
 *
 * Each scenario builds a throwaway SQLite database, drives the real engine
 * classes — no mocks of our own code — and asserts the outcome. A failing
 * assertion exits non-zero, so this doubles as a smoke test.
 *
 * Time is frozen and advanced explicitly: a backoff demonstration that waited
 * for real seconds would take an hour to show its last step.
 */

require __DIR__ . '/../autoload.php';

use Relay\Application\Ingest\IngestResult;
use Relay\Domain\Order\InvalidTransition;
use Relay\Domain\Order\OrderStatus;
use Relay\Http\WebhookSignature;
use Relay\Infrastructure\Erp\FakeErp;
use Relay\Kernel\App;
use Relay\Kernel\Config;
use Relay\Kernel\FrozenClock;

const SECRET = 'demo-secret-not-a-real-credential';

$only = $argv[1] ?? null;
$failures = 0;

$scenarios = [
    'replay' => 'A redelivered webhook does not create a second order',
    'lost-response' => 'A lost response does not become a duplicate downstream',
    'outage' => 'A downstream outage costs no attempts and halts the batch',
    'dead-letter' => 'An exhausted order is parked, then recovered by reconciliation',
    'invalid-transition' => 'An illegal state change is refused',
    'backoff' => 'Retry delays grow exponentially, with jitter',
];

foreach ($scenarios as $name => $title) {
    if ($only !== null && $only !== $name) {
        continue;
    }

    heading($name, $title);
    try {
        $runner = 'scenario_' . str_replace('-', '_', $name);
        $runner();
        ok('scenario passed');
    } catch (Throwable $e) {
        fail($e->getMessage());
        $failures++;
    }
    echo PHP_EOL;
}

if ($only !== null && !array_key_exists($only, $scenarios)) {
    fwrite(STDERR, "Unknown scenario '{$only}'. Known: " . implode(', ', array_keys($scenarios)) . PHP_EOL);
    exit(64);
}

exit($failures === 0 ? 0 : 1);

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

/**
 * The webhook sender retries. It is supposed to. Three identical deliveries of
 * the same event must leave exactly one order here and one order downstream.
 */
function scenario_replay(): void
{
    [$app, $clock, $erp] = bootDemo();
    $ingest = $app->ingest();

    $payload = orderPayload('NW-1001', 'evt_aaa', paid: true);

    $results = [];
    for ($i = 1; $i <= 3; $i++) {
        $results[] = $ingest->handle($payload)->value;
        step("delivery {$i} -> " . end($results));
    }

    assertSame(IngestResult::CREATED->value, $results[0], 'first delivery should create');
    assertSame(IngestResult::DUPLICATE->value, $results[1], 'second delivery should be a duplicate');
    assertSame(IngestResult::DUPLICATE->value, $results[2], 'third delivery should be a duplicate');

    $counts = $app->orders()->countByStatus();
    step('orders stored: ' . json_encode($counts));
    assertSame(1, array_sum($counts), 'exactly one order should exist');

    // And the side effect stays single too, all the way downstream.
    $app->queue()->runCycle('demo');
    step("downstream orders: {$erp->orderCount()}");
    assertSame(1, $erp->orderCount(), 'exactly one order should exist downstream');
}

/**
 * The hard case. The downstream system creates the order and the connection
 * drops before we learn its identifier. Naively retrying creates a second,
 * real, duplicated sales order. Here the pending reservation forces a lookup
 * instead of a second write.
 */
function scenario_lost_response(): void
{
    [$app, $clock, $erp] = bootDemo();

    $app->ingest()->handle(orderPayload('NW-2001', 'evt_bbb', paid: true));

    $erp->loseNextResponse = true;
    step('downstream will accept the order, then drop the connection');

    $first = $app->queue()->runCycle('demo');
    step('cycle 1: ' . json_encode(array_filter($first, static fn ($v) => $v !== null && $v !== 0)));

    $order = $app->orders()->findByReference('NW-2001');
    step('local status after the lost response: ' . $order['status']);
    step("downstream orders: {$erp->orderCount()} (it DID land)");
    assertSame(1, $erp->orderCount(), 'the order landed downstream exactly once');

    // Time passes, the order becomes claimable again, and the second attempt
    // asks rather than assumes.
    $clock->advance(3600);
    $second = $app->queue()->runCycle('demo');
    step('cycle 2: ' . json_encode(array_filter($second, static fn ($v) => $v !== null && $v !== 0)));

    $order = $app->orders()->findByReference('NW-2001');
    step("final status: {$order['status']}, downstream id: {$order['erp_reference']}");

    assertSame(OrderStatus::REGISTERED->value, $order['status'], 'order should end registered');
    assertSame(1, $erp->orderCount(), 'still exactly one order downstream - no duplicate');
}

/**
 * When the dependency is down, the orders are blameless. They must not spend
 * their attempt budget on someone else's outage, and the batch must stop
 * rather than wait out twenty identical timeouts.
 */
function scenario_outage(): void
{
    [$app, $clock, $erp] = bootDemo();

    foreach (range(1, 5) as $n) {
        $app->ingest()->handle(orderPayload(sprintf('NW-30%02d', $n), "evt_c{$n}", paid: true));
    }
    step('5 paid orders queued');

    $erp->goDown();
    step('downstream goes down');

    $stats = $app->queue()->runCycle('demo');
    step('cycle: ' . json_encode(array_filter($stats, static fn ($v) => $v !== null && $v !== 0)));
    assertSame('downstream unavailable', $stats['halted'], 'batch should halt on the outage');

    $attempts = [];
    foreach (range(1, 5) as $n) {
        $order = $app->orders()->findByReference(sprintf('NW-30%02d', $n));
        $attempts[] = (int) $order['attempts'];
    }
    step('attempts charged per order: ' . implode(', ', $attempts));
    assertSame(0, max($attempts), 'an outage must not consume any attempt');

    $erp->comeBackUp();
    $clock->advance(60);
    step('downstream recovers');

    $stats = $app->queue()->runCycle('demo');
    step('cycle: ' . json_encode(array_filter($stats, static fn ($v) => $v !== null && $v !== 0)));
    step("downstream orders: {$erp->orderCount()}");
    assertSame(5, $erp->orderCount(), 'all five should register once the outage ends');
}

/**
 * A genuinely failing order stops after its budget, and parks in a state a
 * human can act on. Reconciliation then discovers it existed downstream after
 * all and repairs the status instead of re-sending it.
 */
function scenario_dead_letter(): void
{
    [$app, $clock, $erp] = bootDemo(maxAttempts: 3);

    $app->ingest()->handle(orderPayload('NW-4001', 'evt_ddd', paid: true));

    // Unclassified errors, not an outage: these DO consume the attempt budget.
    // (An outage would not - see the `outage` scenario, where attempts stay 0.)
    $erp->breakNext(3);

    for ($cycle = 1; $cycle <= 4; $cycle++) {
        $app->queue()->runCycle('demo');
        $order = $app->orders()->findByReference('NW-4001');
        step(sprintf('cycle %d -> status=%s attempts=%s', $cycle, $order['status'], $order['attempts']));
        // Jump past whatever backoff was scheduled.
        $clock->advance(7200);
    }

    $order = $app->orders()->findByReference('NW-4001');
    assertSame(OrderStatus::DEAD_LETTER->value, $order['status'], 'order should be parked in dead_letter');

    // dead_letter is a waiting room, not a bin: the order can still move.
    step('dead_letter is NOT terminal: ' . (OrderStatus::DEAD_LETTER->isTerminal() ? 'wrong' : 'correct'));
    assertSame(false, OrderStatus::DEAD_LETTER->isTerminal(), 'dead_letter must not be terminal');

    // Now suppose it had actually landed downstream all along.
    $erp->registerOrder(['reference' => 'NW-4001'], 'manual:NW-4001');
    step('downstream turns out to hold the order after all');

    $result = $app->reconciler()->run();
    step('reconcile: ' . json_encode($result));

    $order = $app->orders()->findByReference('NW-4001');
    step("status after reconciliation: {$order['status']} ({$order['erp_reference']})");
    assertSame(OrderStatus::REGISTERED->value, $order['status'], 'reconciliation should repair the status');
}

/**
 * Nothing writes `status` directly, so an impossible move is impossible rather
 * than merely discouraged.
 */
function scenario_invalid_transition(): void
{
    [$app] = bootDemo();

    $app->ingest()->handle(orderPayload('NW-5001', 'evt_eee', paid: true));
    $order = $app->orders()->findByReference('NW-5001');
    $id = (int) $order['id'];

    step("order is {$order['status']}");

    // ready -> registered skips the dispatch entirely. If this were allowed,
    // an order could be marked as existing downstream without anyone ever
    // having sent it.
    $refused = false;
    try {
        $app->orders()->transition($id, OrderStatus::REGISTERED, 'demo');
    } catch (InvalidTransition $e) {
        $refused = true;
        step('refused: ' . $e->getMessage());
    }
    assertSame(true, $refused, 'ready -> registered must be refused');

    // The legal path works, and every step of it is recorded.
    $app->orders()->transition($id, OrderStatus::DISPATCHING, 'demo');
    $app->orders()->markRegistered($id, 'ATLAS-99999', 'demo');

    $trail = $app->orders()->transitionsOf($id);
    step('audit trail:');
    foreach ($trail as $row) {
        step(sprintf('   %-16s -> %-12s by %s', $row['from_status'], $row['to_status'], $row['actor']));
    }
    assertSame(true, count($trail) >= 3, 'every transition should be recorded');

    // And terminal really is terminal.
    $refused = false;
    try {
        $app->orders()->transition($id, OrderStatus::DISPATCHING, 'demo');
    } catch (InvalidTransition) {
        $refused = true;
    }
    step('registered -> dispatching refused: ' . ($refused ? 'yes' : 'no'));
    assertSame(true, $refused, 'a terminal order must not move');
}

/**
 * Shows the jitter window per attempt. The window doubles; the actual delay is
 * a uniform pick inside it, so a hundred orders failing together do not all
 * come back at the same instant.
 */
function scenario_backoff(): void
{
    $backoff = new Relay\Application\Queue\Backoff(baseSeconds: 10, maxSeconds: 3600);

    step(sprintf('%-9s %-14s %s', 'attempt', 'window', 'sample delays'));
    foreach (range(1, 10) as $attempt) {
        $samples = [];
        for ($i = 0; $i < 4; $i++) {
            $samples[] = $backoff->delayFor($attempt);
        }
        step(sprintf(
            '%-9d 0..%-11s %s',
            $attempt,
            $backoff->windowFor($attempt) . 's',
            implode('s, ', $samples) . 's',
        ));
    }

    assertSame(10, $backoff->windowFor(1), 'first window should equal the base');
    assertSame(3600, $backoff->windowFor(20), 'window should stop at the maximum');

    $window = $backoff->windowFor(8);
    for ($i = 0; $i < 50; $i++) {
        $delay = $backoff->delayFor(8);
        assertSame(true, $delay >= 0 && $delay <= $window, 'delay must fall inside the window');
    }
    step("50 samples at attempt 8 all fell within 0..{$window}s");
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

/** @return array{0:App,1:FrozenClock,2:FakeErp} */
function bootDemo(int $maxAttempts = 5): array
{
    $root = dirname(__DIR__);
    $dir = $root . '/var';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir . '/demo-' . bin2hex(random_bytes(4)) . '.sqlite';
    register_shutdown_function(static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    });

    $config = Config::fromArray([
        'DB_DRIVER' => 'sqlite',
        'DB_SQLITE_PATH' => $path,
        'QUEUE_BATCH_SIZE' => '20',
        'QUEUE_LEASE_SECONDS' => '300',
        'QUEUE_MAX_ATTEMPTS' => (string) $maxAttempts,
        'QUEUE_BACKOFF_BASE_SECONDS' => '10',
        'LOG_LEVEL' => 'silent',
    ]);

    $clock = new FrozenClock();
    $erp = new FakeErp();
    $app = new App($config, $root, $clock, $erp);
    $app->migrator()->migrate();

    return [$app, $clock, $erp];
}

/** @return array<string,mixed> */
function orderPayload(string $reference, string $eventId, bool $paid): array
{
    return [
        'event_id' => $eventId,
        'event_type' => $paid ? 'order.paid' : 'order.created',
        'order' => [
            'reference' => $reference,
            'currency' => 'USD',
            'total' => '129.90',
            'financial_status' => $paid ? 'paid' : 'pending',
            'customer' => ['id' => 'cus_demo', 'email' => 'buyer@northwind.invalid'],
        ],
    ];
}

function heading(string $name, string $title): void
{
    echo PHP_EOL . colour("== {$name} ", '1;36') . colour($title, '0;36') . PHP_EOL;
    echo str_repeat('-', 78) . PHP_EOL;
}

function step(string $message): void
{
    echo '   ' . $message . PHP_EOL;
}

function ok(string $message): void
{
    echo colour('   PASS  ', '1;32') . $message . PHP_EOL;
}

function fail(string $message): void
{
    echo colour('   FAIL  ', '1;31') . $message . PHP_EOL;
}

function colour(string $text, string $code): string
{
    // No colour when the output is being piped or redirected.
    static $tty = null;
    $tty ??= function_exists('stream_isatty') && @stream_isatty(STDOUT);

    return $tty ? "\033[{$code}m{$text}\033[0m" : $text;
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s (expected %s, got %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}
