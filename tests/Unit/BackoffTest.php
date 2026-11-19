<?php

declare(strict_types=1);

namespace Relay\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Relay\Application\Queue\Backoff;

final class BackoffTest extends TestCase
{
    #[Test]
    public function the_window_doubles_with_each_attempt(): void
    {
        $backoff = new Backoff(baseSeconds: 10, maxSeconds: 100_000);

        self::assertSame(10, $backoff->windowFor(1));
        self::assertSame(20, $backoff->windowFor(2));
        self::assertSame(40, $backoff->windowFor(3));
        self::assertSame(80, $backoff->windowFor(4));
        self::assertSame(160, $backoff->windowFor(5));
    }

    #[Test]
    public function the_window_stops_at_the_maximum(): void
    {
        $backoff = new Backoff(baseSeconds: 10, maxSeconds: 300);

        self::assertSame(300, $backoff->windowFor(10));
        self::assertSame(300, $backoff->windowFor(50));
    }

    /**
     * A large attempt number must not overflow the exponent into a float, which
     * would make the cap stop applying — the failure mode being an order
     * scheduled to retry some time after the heat death of the universe.
     */
    #[Test]
    public function an_absurd_attempt_number_stays_bounded(): void
    {
        $backoff = new Backoff(baseSeconds: 10, maxSeconds: 3600);

        self::assertSame(3600, $backoff->windowFor(1_000));
        self::assertIsInt($backoff->windowFor(1_000));
    }

    #[Test]
    public function a_zero_or_negative_attempt_is_treated_as_the_first(): void
    {
        $backoff = new Backoff(baseSeconds: 10, maxSeconds: 3600);

        self::assertSame(10, $backoff->windowFor(0));
        self::assertSame(10, $backoff->windowFor(-5));
    }

    #[Test]
    public function the_delay_always_falls_inside_the_window(): void
    {
        $backoff = new Backoff(baseSeconds: 10, maxSeconds: 3600);

        foreach (range(1, 12) as $attempt) {
            $window = $backoff->windowFor($attempt);
            for ($i = 0; $i < 25; $i++) {
                $delay = $backoff->delayFor($attempt);
                self::assertGreaterThanOrEqual(0, $delay);
                self::assertLessThanOrEqual($window, $delay);
            }
        }
    }

    /**
     * Full jitter means the delay is a uniform pick from the whole window, not
     * the window plus noise. Without it, every order that failed during the
     * same incident retries at the same instant and knocks the dependency over
     * again the moment it recovers.
     */
    #[Test]
    public function jitter_spreads_retries_across_the_window(): void
    {
        $backoff = new Backoff(baseSeconds: 60, maxSeconds: 3600);

        $samples = [];
        for ($i = 0; $i < 200; $i++) {
            $samples[] = $backoff->delayFor(5);
        }

        $window = $backoff->windowFor(5);
        $distinct = count(array_unique($samples));

        self::assertGreaterThan(20, $distinct, 'delays should be spread, not clustered');
        self::assertLessThan($window * 0.75, min($samples), 'some retries should land early in the window');
        self::assertGreaterThan($window * 0.25, max($samples), 'some retries should land late in the window');
    }

    #[Test]
    public function the_randomizer_can_be_injected_for_determinism(): void
    {
        $backoff = new Backoff(
            baseSeconds: 10,
            maxSeconds: 3600,
            randomizer: static fn (int $min, int $max): int => $max,
        );

        self::assertSame(10, $backoff->delayFor(1));
        self::assertSame(80, $backoff->delayFor(4));

        $now = new DateTimeImmutable('2026-01-01 00:00:00');
        self::assertSame(
            '2026-01-01 00:01:20',
            $backoff->nextAttemptAt($now, 4)->format('Y-m-d H:i:s'),
        );
    }
}
