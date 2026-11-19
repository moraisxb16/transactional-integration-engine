<?php

declare(strict_types=1);

/**
 * The queue worker.
 *
 * Usage:
 *   php bin/worker.php --once            run a single cycle and exit
 *   php bin/worker.php --interval=5      loop, sleeping 5s between cycles
 *   php bin/worker.php --max-life=1800   exit after this many seconds
 *
 * Running several at once is the normal case, not an edge case: the claim is
 * atomic, so workers do not collide.
 *
 * ON DEPLOYING A LONG-RUNNING WORKER
 * PHP does not reload a class once it is loaded, so a worker already in flight
 * keeps executing the OLD code after a deploy — indefinitely, if nothing stops
 * it. That is why --max-life exists and why it defaults to something finite: the
 * process ends on its own and the supervisor starts a replacement on the new
 * code. A bounded lifetime is the cheapest fix for a problem that otherwise
 * surfaces as "the bug is still happening and I definitely deployed the fix".
 */

require __DIR__ . '/../autoload.php';

use Relay\Kernel\App;

$options = getopt('', ['once', 'interval::', 'max-life::', 'worker-id::']);

$once = array_key_exists('once', $options);
$interval = (int) ($options['interval'] ?? 5);
$maxLife = (int) ($options['max-life'] ?? 1800);
$workerId = (string) ($options['worker-id'] ?? substr(bin2hex(random_bytes(4)), 0, 8));

$app = App::boot(dirname(__DIR__));
$queue = $app->queue();
$logger = $app->logger();

$startedAt = time();
$running = true;

// Graceful shutdown: finish the order in hand, then stop. Killing a worker
// mid-dispatch is survivable thanks to the lease, but it costs the lease
// duration before the order is picked up again.
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use (&$running, $logger): void {
            $logger->info('worker', 'Signal received; finishing the current cycle then stopping.');
            $running = false;
        });
    }
}

$logger->info('worker', 'Worker started', [
    'worker_id' => $workerId,
    'mode' => $once ? 'once' : 'loop',
    'interval' => $interval,
    'max_life' => $maxLife,
]);

do {
    $stats = $queue->runCycle($workerId);

    $logger->info('worker', 'Cycle finished', $stats + ['worker_id' => $workerId]);

    if ($once || !$running) {
        break;
    }

    if ($maxLife > 0 && (time() - $startedAt) >= $maxLife) {
        $logger->info('worker', 'Maximum lifetime reached; exiting so a fresh process can take over.', [
            'worker_id' => $workerId,
        ]);
        break;
    }

    // Nothing to do means nothing to do. Sleeping here keeps an idle queue from
    // becoming a busy loop against the database.
    if ($stats['claimed'] === 0 && $interval > 0) {
        sleep($interval);
    }
} while ($running);

$logger->info('worker', 'Worker stopped', ['worker_id' => $workerId]);
