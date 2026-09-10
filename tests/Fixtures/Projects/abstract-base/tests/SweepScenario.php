<?php

declare(strict_types=1);

namespace App\Tests;

use App\Beta;
use PHPUnit\Framework\TestCase;

/**
 * Same shape as `SweepBaseTest`, named WITHOUT the configured test suffix — variant 2 of
 * the abstract-base reproduction. The natural remedy for the warning `SweepBaseTest`
 * triggers (rename the base away from the test suffix) is exactly this shape, and it is
 * also how a shared base not itself meant to run is usually named in the first place. The
 * declaring-file defect survives that rename undiminished: `TestMethod::file()` still
 * resolves to wherever the method is declared, and this file no longer even matches
 * `Select\TestPaths::isTestFile()`, so it is not "a test file" this package tracks at all.
 */
abstract class SweepScenario extends TestCase
{
    public function testBetaAddsTen(): void
    {
        self::assertSame(20, Beta::compute(10));
    }
}
