<?php

declare(strict_types=1);

namespace App\Tests;

use App\Money;
use Manuglopez\Replay\Attributes\NotCacheable;
use PHPUnit\Framework\TestCase;

/**
 * Fixture for the hermeticity "partially cacheable" scenario: only the method-level
 * `#[NotCacheable]` test always executes for real; its sibling replays normally.
 */
final class PartiallyCacheableTest extends TestCase
{
    #[NotCacheable]
    public function testUsesTheClock(): void
    {
        $price = Money::fromCents(500, 'EUR');

        self::assertSame(500, $price->amount);
    }

    public function testIsOrdinaryAndCacheable(): void
    {
        $price = Money::fromCents(250, 'EUR');

        self::assertSame(250, $price->amount);
    }
}
