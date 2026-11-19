<?php

declare(strict_types=1);

/**
 * Sweeps dead letters and repairs the status of any that exist downstream.
 *
 * Usage:  php bin/reconcile.php [--limit=50]
 *
 * Intended for a schedule of its own — hourly is ample. It is deliberately NOT
 * part of the worker cycle: see Application\Queue\ReconcileDeadLetters for why
 * it was moved out.
 */

require __DIR__ . '/../autoload.php';

use Relay\Kernel\App;

$options = getopt('', ['limit::']);
$limit = (int) ($options['limit'] ?? 50);

$app = App::boot(dirname(__DIR__));
$result = $app->reconciler()->run($limit);

printf(
    "swept=%d recovered=%d unavailable=%d%s",
    $result['swept'],
    $result['recovered'],
    $result['unavailable'],
    PHP_EOL,
);

// A non-zero recovery count is worth a distinct exit code: it means orders were
// registered downstream while this system believed they had failed, which is
// something a scheduler should be able to alert on without parsing output.
exit($result['recovered'] > 0 ? 2 : 0);
