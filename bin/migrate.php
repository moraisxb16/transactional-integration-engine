<?php

declare(strict_types=1);

/**
 * Applies pending migrations.
 *
 * Usage:  php bin/migrate.php
 */

require __DIR__ . '/../autoload.php';

use Relay\Kernel\App;

$app = App::boot(dirname(__DIR__));

$applied = $app->migrator()->migrate();

if ($applied === []) {
    echo "Schema is already up to date." . PHP_EOL;
    exit(0);
}

foreach ($applied as $name) {
    echo "applied  {$name}" . PHP_EOL;
}

echo sprintf('%d migration(s) applied.', count($applied)) . PHP_EOL;
