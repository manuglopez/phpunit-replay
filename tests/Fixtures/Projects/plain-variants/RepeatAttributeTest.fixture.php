<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money;
use PHPUnit\Framework\Attributes\Repeat;
use PHPUnit\Framework\TestCase;

/**
 * Fixture for the `#[Repeat]` hermeticity scenario (PHPUnit >= 13.3 only): the decorated
 * method must execute for real on every run, including a second one where nothing
 * changed — the whole point of `#[Repeat]` is to keep re-running the test to surface
 * flakiness or order dependence, which a replay would silently defeat.
 */
final class RepeatAttributeTest extends TestCase
{
    #[Repeat(3)]
    public function testRepeatsEveryTime(): void
    {
        $price = Money::fromCents(500, 'EUR');

        self::assertSame(500, $price->amount);
    }
}
