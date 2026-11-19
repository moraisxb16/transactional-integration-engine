<?php

declare(strict_types=1);

namespace Relay\Kernel;

use RuntimeException;

/**
 * Configuration read from the environment, with .env as a convenience for local
 * runs only.
 *
 * `require()` throws instead of returning a default. A missing database password
 * should stop the process at startup with a clear message, not surface later as
 * an authentication failure against the ERP that looks like the ERP's fault.
 */
final class Config
{
    /** @var array<string,string> */
    private array $values;

    /** @param array<string,string> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $values = [];

        if ($envFile !== null && is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = trim($value, " \t\"'");
            }
        }

        // A real environment variable always wins over the file: that is how the
        // container and the CI runner override what .env happens to contain.
        foreach ($_ENV + $_SERVER as $key => $value) {
            if (is_string($key) && is_scalar($value) && preg_match('/^[A-Z][A-Z0-9_]*$/', $key) === 1) {
                $values[$key] = (string) $value;
            }
        }

        return new self($values);
    }

    /** @param array<string,string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new RuntimeException("Required configuration '{$key}' is missing. See .env.example.");
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
