<?php

declare(strict_types=1);

namespace App\Tests;

// Rewritten comments only: the assertions below are byte-for-byte identical
// to tests/CommentedTest.php once whitespace and comments are stripped, so
// this variant exercises the "cosmetic change executes nothing" scenario.

use App\Money;
use PHPUnit\Framework\TestCase;

// Money arithmetic, covered from a file whose comments churn independently
// of its logic.
final class CommentedTest extends TestCase
{
    public function testAddingTwoAmountsInTheSameCurrency(): void
    {
        /* two amounts, same currency */
        $a = Money::fromCents(400, 'EUR');
        $b = Money::fromCents(600, 'EUR');

        /* add them */
        $sum = $a->add($b);

        /* amount and currency both preserved */
        self::assertSame(1000, $sum->amount);
        self::assertSame('EUR', $sum->currency);
    }

    public function testZeroIsTheIdentityForAddition(): void
    {
        /* zero must not change the other operand */
        $money = Money::fromCents(750, 'EUR');
        $zero = Money::zero('EUR');

        self::assertTrue($money->add($zero)->equals($money));
    }
}
