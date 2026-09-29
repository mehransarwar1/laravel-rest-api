<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_converts_valid_amounts_to_and_from_cents(): void
    {
        $this->assertSame(0, Money::toCents('0.00'));
        $this->assertSame(1, Money::toCents('0.01'));
        $this->assertSame(199, Money::toCents('1.99'));
        $this->assertSame(1010, Money::toCents('10.10'));
        $this->assertSame(999999999999, Money::toCents('9999999999.99'));

        $this->assertSame('0.00', Money::fromCents(0));
        $this->assertSame('0.01', Money::fromCents(1));
        $this->assertSame('1.99', Money::fromCents(199));
        $this->assertSame('10.10', Money::fromCents(1010));
        $this->assertSame('9999999999.99', Money::fromCents(999999999999));
    }

    public function test_rejects_invalid_and_overflow_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('-0.01');
    }

    public function test_rejects_more_than_two_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('1.999');
    }

    public function test_rejects_values_that_exceed_decimal_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('99999999999.99');
    }

    public function test_rejects_non_numeric_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('abc');
    }
}
