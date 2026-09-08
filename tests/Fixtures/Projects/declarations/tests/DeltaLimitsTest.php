<?php

declare(strict_types=1);

namespace App\Tests;

use App\Limits;
use PHPUnit\Framework\TestCase;

/**
 * The hardest position: this test reads a declaration-only file and touches no file with
 * a method body at all, so it has no behavioural dependency to hop through except its own
 * source — which is exactly why `Analysis\StaticEdges` treats the test file itself as a
 * hop source.
 */
final class DeltaLimitsTest extends TestCase
{
    public function test_limits_are_sane(): void
    {
        self::assertSame(3, Limits::REQUIRED_REVIEWS);
        self::assertGreaterThan(0, Limits::PASSING_SCORE);
    }
}
