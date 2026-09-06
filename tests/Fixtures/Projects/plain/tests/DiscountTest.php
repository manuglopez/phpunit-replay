<?php

declare(strict_types=1);

namespace App\Tests;

use App\Discount;
use App\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DiscountTest extends TestCase
{
    public function testPercentageDiscountReducesPrice(): void
    {
        $discount = Discount::percentage(25.0);
        $price = Money::fromCents(2000, 'EUR');

        $result = $discount->apply($price);

        self::assertSame(1500, $result->amount);
    }

    public function testFlatDiscountReducesPrice(): void
    {
        $discount = Discount::flat(300);
        $price = Money::fromCents(2000, 'EUR');

        $result = $discount->apply($price);

        self::assertSame(1700, $result->amount);
    }

    public function testFlatDiscountLargerThanPriceClampsToZero(): void
    {
        $discount = Discount::flat(5000);
        $price = Money::fromCents(2000, 'EUR');

        $result = $discount->apply($price);

        self::assertTrue($result->isZero());
    }

    public function testFreeWhenFullyDiscounted(): void
    {
        $discount = Discount::percentage(100.0);
        $price = Money::fromCents(999, 'EUR');

        self::assertTrue($discount->isFree($price));
    }

    public function testInvalidPercentageThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Discount::percentage(150.0);
    }

    public function testNegativeFlatAmountThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Discount::flat(-1);
    }
}
