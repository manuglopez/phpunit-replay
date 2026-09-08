<?php

declare(strict_types=1);

namespace App\Tests;

use App\ReviewDecision;
use App\ReviewPolicy;
use PHPUnit\Framework\TestCase;

/** Same again: a direct assertion on a case, no first-loader luck. */
final class GammaPendingTest extends TestCase
{
    public function test_holds_a_review_with_too_few_reviewers(): void
    {
        $policy = new ReviewPolicy();

        self::assertSame(ReviewDecision::Pending, $policy->decide(80, 1));
        self::assertSame('decision: pending', $policy->summarise(ReviewDecision::Pending));
    }
}
