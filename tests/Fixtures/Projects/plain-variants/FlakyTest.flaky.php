<?php

declare(strict_types=1);

namespace App\Tests;

use Manuglopez\Replay\Attributes\NotCacheable;
use PHPUnit\Framework\TestCase;

/**
 * Fixture for the automatic-quarantine scenario (SPEC.md §8.3): the outcome depends on
 * an environment variable, not on any file content, so it can flip between recorded
 * passes without touching the fixture's git history — exactly what a flaky test does.
 */
final class FlakyTest extends TestCase
{
    /**
     * Deliberate, not a leftover: without this, an unchanged content key means an
     * ordinary second pass simply replays the cached result — the flip this fixture
     * exists to produce would never be observed, because the test body would never run
     * a second time at all. `--filter` used to be how a test forced this re-execution
     * without a full record, but a CLI selection now persists nothing at all (this
     * package's own rule 2), so it can no longer surface a flip through the real
     * pipeline either. `#[NotCacheable]` forces the real re-execution instead, through
     * an entirely ordinary, unfiltered `run` — see
     * `QuarantineFlipDetectionThroughAPlainRunTest`.
     */
    #[NotCacheable('depends on FIXTURE_FLIP, not file content: must always re-execute so a flip can ever be observed')]
    public function testDependsOnAnExternalFlag(): void
    {
        $shouldFail = getenv('FIXTURE_FLIP') === '1';

        self::assertFalse($shouldFail, 'FIXTURE_FLIP was set: flipping this test on purpose.');
    }
}
