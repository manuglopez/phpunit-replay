<?php

declare(strict_types=1);

namespace App\Tests;

/*
 * Fixture note: this test file exists to exercise the "comment-only change"
 * scenario in the replay integration suite. The behaviour-identical variant
 * lives at tests/Fixtures/Projects/plain-variants/CommentedTest.comment-only.php
 * and differs from this file only in comments and whitespace.
 */

use App\Money;

/**
 * Covers Money arithmetic once more, from a file whose comments are expected
 * to churn independently of its logic.
 */
final class CommentedTest extends TestCase
{
    public function testAddingTwoAmountsInTheSameCurrency(): void
    {
        // Arrange: two amounts in the same currency.
        $a = Money::fromCents(400, 'EUR');
        $b = Money::fromCents(600, 'EUR');

        // Act: add them together.
        $sum = $a->add($b);

        // Assert: the amount and currency are both preserved.
        self::assertSame(1000, $sum->amount);
        self::assertSame('EUR', $sum->currency);
    }

    public function testZeroIsTheIdentityForAddition(): void
    {
        // A zero amount should not change the other operand.
        $money = Money::fromCents(750, 'EUR');
        $zero = Money::zero('EUR');

        self::assertTrue($money->add($zero)->equals($money));
    }
}
