<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money;
use PHPUnit\Framework\Attributes\Depends;

final class DependsTest extends TestCase
{
    public function testFirst(): Money
    {
        $money = Money::fromCents(500, 'EUR');

        self::assertSame(500, $money->amount);

        return $money;
    }

    #[Depends('testFirst')]
    public function testSecondUsesReturnedMoney(Money $money): void
    {
        $doubled = $money->multiply(2.0);

        self::assertSame(1000, $doubled->amount);
        self::assertSame('EUR', $doubled->currency);
    }
}
