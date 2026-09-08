<?php

declare(strict_types=1);

namespace App;

/**
 * The one file here with real method bodies, and therefore the only one a coverage driver
 * can attribute order-independently. It names both declaration-only files, which is what
 * `Analysis\StaticEdges` hops through.
 */
final class ReviewPolicy
{
    public function decide(int $score, int $reviews): ReviewDecision
    {
        if ($reviews < Limits::REQUIRED_REVIEWS) {
            return ReviewDecision::Pending;
        }

        return $score >= Limits::PASSING_SCORE ? ReviewDecision::Approved : ReviewDecision::Rejected;
    }

    public function summarise(ReviewDecision $decision): string
    {
        return 'decision: ' . $decision->value;
    }
}
