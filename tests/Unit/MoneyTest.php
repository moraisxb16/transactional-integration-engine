<?php

declare(strict_types=1);

namespace Relay\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Relay\Domain\Shared\Money;

final class MoneyTest extends TestCase
{
    #[Test]
    public function it_parses_decimal_strings_without_losing_cents(): void
    {
        self::assertSame(1990, Money::fromDecimalString('19.90')->amountInCents);
        self::assertSame(1999, Money::fromDecimalString('19.99')->amountInCents);
        self::assertSame(1900, Money::fromDecimalString('19.0')->amountInCents);
        self::assertSame(1900, Money::fromDecimalString('19')->amountInCents);
        self::assertSame(-500, Money::fromDecimalString('-5.00')->amountInCents);
        self::assertSame(0, Money::fromDecimalString('0')->amountInCents);
    }

    /**
     * The reason this class exists. 0.1 + 0.2 is not 0.3 in binary floating
     * point, and an order total that is one cent off is rejected downstream
     * exactly as hard as one that is a hundred off.
     */
    #[Test]
    public function addition_is_exact(): void
    {
        $total = Money::fromDecimalString('0.10')->add(Money::fromDecimalString('0.20'));

        self::assertSame(30, $total->amountInCents);
        self::assertSame('0.30', $total->toDecimalString());

        $sum = Money::fromCents(0);
        for ($i = 0; $i < 1000; $i++) {
            $sum = $sum->add(Money::fromDecimalString('0.01'));
        }
        self::assertSame(1000, $sum->amountInCents);
        self::assertSame('10.00', $sum->toDecimalString());
    }

    #[Test]
    public function it_round_trips_through_its_decimal_form(): void
    {
        foreach (['0.00', '0.05', '9.99', '129.90', '1000.00', '-7.42'] as $value) {
            self::assertSame($value, Money::fromDecimalString($value)->toDecimalString());
        }
    }

    #[Test]
    public function it_refuses_input_it_cannot_represent_exactly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Three decimal places cannot be held in cents, and silently rounding
        // is how a total drifts away from the one the customer was charged.
        Money::fromDecimalString('1.005');
    }

    #[Test]
    public function it_refuses_junk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString('not a number');
    }

    #[Test]
    public function it_refuses_to_mix_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromCents(100, 'USD')->add(Money::fromCents(100, 'EUR'));
    }

    #[Test]
    public function it_validates_the_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromCents(100, 'dollars');
    }

    #[Test]
    public function it_compares_on_both_amount_and_currency(): void
    {
        self::assertTrue(Money::fromCents(100, 'USD')->equals(Money::fromCents(100, 'USD')));
        self::assertFalse(Money::fromCents(100, 'USD')->equals(Money::fromCents(100, 'EUR')));
        self::assertFalse(Money::fromCents(100, 'USD')->equals(Money::fromCents(101, 'USD')));
    }

    #[Test]
    public function it_subtracts_and_multiplies(): void
    {
        $price = Money::fromDecimalString('19.90');

        self::assertSame('59.70', $price->multipliedBy(3)->toDecimalString());
        self::assertSame('9.90', $price->subtract(Money::fromDecimalString('10.00'))->toDecimalString());
        self::assertTrue($price->subtract($price)->isZero());
        self::assertTrue($price->subtract(Money::fromDecimalString('20.00'))->isNegative());
    }
}
