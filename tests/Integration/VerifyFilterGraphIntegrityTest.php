<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * Bug fix: `RunPipeline::verify()` used to pass `recordsEdges: true` (and a `$complete`
 * derived from the run alone) whatever the user asked PHPUnit to run, so a `verify`
 * carrying a partial CLI selection (`--filter`/`--group`/`--testsuite`/an explicit path)
 * rewrote the graph from a run that never covered the suite: the executed test file's
 * edges were replaced by whatever coverage the narrower selection attributed to it,
 * `$complete` then pruned every sibling result the filter excluded, and it published a
 * baseline sha for a partial run. Reported from a real 9056-test suite as two identical
 * back-to-back `verify` runs claiming "would replay" of 6304 and then 9056 — each run
 * corrupting the graph the next one read.
 *
 * `verify()` now derives `recordsEdges` from `ConfigurationReader::hasPartialSelection()`,
 * exactly the guarantee {@see Scenario06FilterDoesNotTouchEdgesTest} already asserts for
 * `run`'s results-only path. This test asserts the same thing for `verify`: a `--filter`
 * touches only the one test it ran, never the graph's edges, its other cached results, or
 * the published baseline sha.
 */
final class VerifyFilterGraphIntegrityTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_verify_with_a_filter_does_not_rewrite_edges_prune_results_or_publish_a_baseline(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $before = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($before);
        $shaBefore = $before->recordedSha('main');
        $edgesBefore = self::allEdges($before);
        $resultsBefore = $before->results('main');

        // 35 tests recorded above; only one runs here.
        $result = $this->fixture->replay(['verify', '--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('1 / 1 (100%)', $result['stdout']);

        $after = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($after);

        // The baseline sha published by the full `record` above must survive a filtered
        // `verify` untouched — a partial run has no business publishing (or re-publishing)
        // a baseline for the whole suite.
        self::assertSame($shaBefore, $after->recordedSha('main'));

        // Edges for every test file — including the one file the filtered test lives in —
        // must be exactly what the full record produced, not rewritten from a run that
        // only ever loaded that one file's own test.
        self::assertSame($edgesBefore, self::allEdges($after));

        $testId = 'App\Tests\MoneyTest::testAdditionAndSubtractionKeepCurrency';

        $updated = $after->result('main', $testId);
        self::assertNotNull($updated);
        self::assertSame(0, $updated['status']);

        // Every other cached result (34 of the 35) must be byte-for-byte what the full
        // record wrote — none pruned, none rewritten by a $complete that had no business
        // being true for a partial selection.
        unset($resultsBefore[$testId]);
        $resultsAfter = $after->results('main');
        unset($resultsAfter[$testId]);
        self::assertSame($resultsBefore, $resultsAfter);
        self::assertCount(34, $resultsAfter);
    }

    /** @return array<string, list<string>> test file => sorted dependencies */
    private static function allEdges(Graph $graph): array
    {
        $edges = [];

        foreach ($graph->allTestFiles() as $testFile) {
            $deps = $graph->dependenciesOf($testFile);
            sort($deps);
            $edges[$testFile] = $deps;
        }

        ksort($edges);

        return $edges;
    }
}
