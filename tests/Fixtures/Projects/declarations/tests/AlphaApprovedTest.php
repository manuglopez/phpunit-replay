<?php

declare(strict_types=1);

namespace App\Tests;

use App\ReviewDecision;
use App\ReviewPolicy;
use PHPUnit\Framework\TestCase;

/**
 * First test file PHPUnit reaches, so this is the process's first loader: with
 * `static_declaration_edges` off, `src/ReviewDecision.php` and `src/Limits.php` are
 * credited to THIS test and to no other, however many of them assert against them.
 */
final class AlphaApprovedTest extends TestCase
{
    public function test_approves_a_passing_score(): void
    {
        self::assertSame(ReviewDecision::Approved, (new ReviewPolicy())->decide(80, 3));
    }
}
