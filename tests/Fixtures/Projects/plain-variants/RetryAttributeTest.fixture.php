<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money;
use PHPUnit\Framework\Attributes\Retry;
use PHPUnit\Framework\TestCase;

/**
 * Fixture for the `#[Retry]` hermeticity scenario (PHPUnit >= 13.3 only): the decorated
 * method passes on its first attempt, so `id()` is never repetition/attempt-suffixed —
 * exactly the case that a bare `Class::method` not-cacheable entry would miss, and the
 * one this fixture exists to exercise end to end.
 */
final class RetryAttributeTest extends TestCase
{
    #[Retry(3)]
    public function testRetriesEveryTime(): void
    {
        $price = Money::fromCents(500, 'EUR');

        self::assertSame(500, $price->amount);
    }
}
