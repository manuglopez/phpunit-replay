<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Fixture for the automatic-quarantine scenario (SPEC.md §8.3): the outcome depends on
 * an environment variable, not on any file content, so it can flip between recorded
 * passes without touching the fixture's git history — exactly what a flaky test does.
 */
final class FlakyTest extends TestCase
{
    public function testDependsOnAnExternalFlag(): void
    {
        $shouldFail = getenv('FIXTURE_FLIP') === '1';

        self::assertFalse($shouldFail, 'FIXTURE_FLIP was set: flipping this test on purpose.');
    }
}
