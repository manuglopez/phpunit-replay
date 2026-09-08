<?php

declare(strict_types=1);

namespace App\Tests;

use App\ReviewDecision;
use App\ReviewPolicy;
use PHPUnit\Framework\TestCase;

/** Asserts directly against a case of the enum, and is not the file's first loader. */
final class BetaRejectedTest extends TestCase
{
    public function test_rejects_a_failing_score(): void
    {
        self::assertSame(ReviewDecision::Rejected, (new ReviewPolicy())->decide(10, 3));
    }
}
