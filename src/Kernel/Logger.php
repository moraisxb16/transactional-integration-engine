<?php

declare(strict_types=1);

namespace Relay\Kernel;

/**
 * Structured logging to a stream, one JSON object per line.
 *
 * JSON rather than prose because the first thing anyone does with these lines is
 * filter them: every message carries the channel and, where there is one, the
 * order reference, so "what happened to NW-1007" is a grep and not a reading
 * exercise.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    /**
     * Suppresses everything. Exists for the demo scripts, whose output is the
     * point: interleaving structured log lines with the narration would bury
     * the thing a reader opened the demo to see.
     */
    public const SILENT = 'silent';

    private const SEVERITY = [
        self::DEBUG => 10,
        self::INFO => 20,
        self::WARNING => 30,
        self::ERROR => 40,
        self::SILENT => 100,
    ];

    /** @var resource */
    private $stream;

    /** @param resource $stream */
    public function __construct(
        private readonly Clock $clock,
        $stream = null,
        private readonly string $minimumLevel = self::INFO,
    ) {
        $this->stream = $stream ?? fopen('php://stderr', 'wb');
    }

    /** @param array<string,mixed> $context */
    public function debug(string $channel, string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $channel, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $channel, string $message, array $context = []): void
    {
        $this->log(self::INFO, $channel, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $channel, string $message, array $context = []): void
    {
        $this->log(self::WARNING, $channel, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $channel, string $message, array $context = []): void
    {
        $this->log(self::ERROR, $channel, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $channel, string $message, array $context = []): void
    {
        if ((self::SEVERITY[$level] ?? 0) < (self::SEVERITY[$this->minimumLevel] ?? 0)) {
            return;
        }

        $line = json_encode([
            'at' => $this->clock->now()->format(DATE_RFC3339),
            'level' => $level,
            'channel' => $channel,
            'message' => $message,
            'context' => self::redact($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        fwrite($this->stream, $line . PHP_EOL);
    }

    /**
     * Strip anything that looks like a credential before it reaches a log file.
     *
     * A log line outlives the request and gets copied into tickets and chat
     * messages, so a token that reaches it has effectively been published. The
     * list is deliberately broad: a redacted field that did not need redacting
     * costs nothing, the reverse costs a rotation.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function redact(array $context): array
    {
        $sensitive = ['secret', 'token', 'password', 'authorization', 'signature', 'api_key', 'apikey'];

        $clean = [];
        foreach ($context as $key => $value) {
            $lowered = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitive as $needle) {
                if (str_contains($lowered, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $clean[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $clean[$key] = self::redact($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
