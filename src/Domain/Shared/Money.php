<?php

declare(strict_types=1);

namespace Relay\Domain\Shared;

use InvalidArgumentException;

/**
 * An amount in minor units (cents), with its currency.
 *
 * Integers, never floats. 0.1 + 0.2 is not 0.3 in binary floating point, and an
 * order total that disagrees with the downstream system by one cent is rejected
 * just as hard as one that disagrees by a hundred — with the added cost that
 * nobody believes the bug is real until they reproduce it.
 */
final class Money
{
    private function __construct(
        public readonly int $amountInCents,
        public readonly string $currency,
    ) {
    }

    public static function fromCents(int $cents, string $currency = 'USD'): self
    {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Currency must be a 3-letter ISO code, got '{$currency}'.");
        }

        return new self($cents, $currency);
    }

    /**
     * Parse a decimal string such as "19.90".
     *
     * Takes a string rather than a float so the caller cannot lose precision
     * before we are even involved: json_decode of "19.90" already gives a float,
     * so payload parsing must use JSON_BIGINT_AS_STRING or read the raw text.
     */
    public static function fromDecimalString(string $amount, string $currency = 'USD'): self
    {
        $trimmed = trim($amount);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $trimmed, $m)) {
            throw new InvalidArgumentException("Cannot parse '{$amount}' as a monetary amount.");
        }

        $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return self::fromCents($m[1] === '-' ? -$cents : $cents, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountInCents + $other->amountInCents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountInCents - $other->amountInCents, $this->currency);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->amountInCents * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountInCents === $other->amountInCents
            && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->amountInCents === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountInCents < 0;
    }

    public function toDecimalString(): string
    {
        $sign = $this->amountInCents < 0 ? '-' : '';
        $absolute = abs($this->amountInCents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function __toString(): string
    {
        return $this->toDecimalString() . ' ' . $this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Refusing to combine {$this->currency} with {$other->currency}: convert first, explicitly."
            );
        }
    }
}
