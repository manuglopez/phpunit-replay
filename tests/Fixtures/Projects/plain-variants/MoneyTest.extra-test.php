<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testAdditionAndSubtractionKeepCurrency(): void
    {
        $a = Money::fromCents(1000, 'eur');
        $b = Money::fromCents(250, 'EUR');

        $sum = $a->add($b);
        $diff = $a->subtract($b);

        self::assertSame(1250, $sum->amount);
        self::assertSame('EUR', $sum->currency);
        self::assertSame(750, $diff->amount);
        self::assertFalse($diff->isNegative());
    }

    public function testMultiplyRoundsToNearestCent(): void
    {
        $price = Money::fromCents(333, 'USD');

        $doubled = $price->multiply(2.0);
        $halved = $price->multiply(0.5);

        self::assertSame(666, $doubled->amount);
        self::assertSame(167, $halved->amount);
    }

    public function testComparisonAndFormatting(): void
    {
        $ten = Money::fromCents(1000, 'EUR');
        $five = Money::fromCents(500, 'EUR');
        $negative = Money::fromCents(-150, 'EUR');

        self::assertTrue($ten->greaterThan($five));
        self::assertFalse($five->greaterThan($ten));
        self::assertTrue($ten->equals(Money::fromCents(1000, 'EUR')));
        self::assertSame('10.00 EUR', $ten->format());
        self::assertSame('-1.50 EUR', $negative->format());
    }

    public function testCurrencyMismatchThrows(): void
    {
        $euros = Money::fromCents(100, 'EUR');
        $dollars = Money::fromCents(100, 'USD');

        $this->expectException(InvalidArgumentException::class);

        $euros->add($dollars);
    }

    public function testSkippedOnPurpose(): void
    {
        $this->markTestSkipped('fixture skip');
    }

    /**
     * Extra test appended for the "new test in a known file" integration
     * scenario: MoneyTest keeps every original method and gains exactly one
     * more.
     */
    public function testZeroIsNeitherNegativeNorPositive(): void
    {
        $zero = Money::zero('EUR');

        self::assertFalse($zero->isNegative());
        self::assertTrue($zero->isZero());
    }
}
