<?php

declare(strict_types=1);

namespace App\Tests;

use App\Alpha;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Declares the test methods a family of "sweep" tests runs, shared through inheritance —
 * never itself run by PHPUnit (abstract). Named WITH the configured test suffix on
 * purpose: this is variant 1 of the abstract-base reproduction, the shape actually
 * measured on a real Laravel suite, where PHPUnit's own directory-based discovery tries
 * to load this file too and warns "Class ... declared in ... is abstract"
 * (`PHPUnit\Framework\TestSuite::addTestFile()` catching
 * `PHPUnit\Runner\TestSuiteLoader`'s `ClassIsAbstractException`) — a warning, not a
 * failure, since none of these fixtures set `failOnWarning`.
 *
 * `testAlphaDoublesVarious()` (data-provider-driven) is here to make the data-provider
 * claim in `PHPUnit\TestMethodFile`'s docblock an empirically checked one, not merely a
 * read of `TestMethodBuilder::dataFor()`: `ConcreteFiveTest` (below) inherits it exactly
 * like `testAlphaDoubles()`, over several dataset repetitions of the SAME inherited
 * method, and its recorded edges are asserted the same way.
 */
abstract class SweepBaseTest extends TestCase
{
    public function testAlphaDoubles(): void
    {
        self::assertSame(20, Alpha::compute(10));
    }

    #[DataProvider('doublingCases')]
    public function testAlphaDoublesVarious(int $input, int $expected): void
    {
        self::assertSame($expected, Alpha::compute($input));
    }

    /** @return array<string, array{0: int, 1: int}> */
    public static function doublingCases(): array
    {
        return [
            'small' => [5, 10],
            'large' => [100, 200],
        ];
    }
}
