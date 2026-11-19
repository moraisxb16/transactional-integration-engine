<?php

declare(strict_types=1);

/**
 * PSR-4 autoloader, used when Composer's is not present.
 *
 * The repository has one runtime dependency: PHP. Requiring `composer install`
 * before anyone can watch the demo adds a step and a failure mode for no gain,
 * so the demos and the engine itself run from a checkout. Composer is still the
 * right tool for the dev dependencies — PHPUnit lives there — and its autoloader
 * wins whenever it exists.
 */

$composer = __DIR__ . '/vendor/autoload.php';
if (is_file($composer)) {
    require $composer;

    return;
}

spl_autoload_register(static function (string $class): void {
    foreach (['Relay\\Tests\\' => __DIR__ . '/tests/', 'Relay\\' => __DIR__ . '/src/'] as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
