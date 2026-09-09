<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 6 / §16 acceptance criterion 6: `--filter` (forwarded to PHPUnit
 * after `--`) runs only what was asked, in results-only mode, and persists nothing at all
 * — not the graph's edges, not the recorded baseline sha, and (bug fix: this used to
 * still refresh the filtered test's own cached result — the false-green vector rule 2
 * closes) not even the filtered test's own result (docs/INTERNALS.md step 7).
 */
final class Scenario06FilterDoesNotTouchEdgesTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_filter_runs_only_the_requested_test_and_does_not_corrupt_the_graph(): void
    {
        $before = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($before);
        $shaBefore = $before->recordedSha('main');
        $edgesBefore = self::allEdges($before);

        $result = $this->fixture->replay(['--', '--filter', 'testAdditionAndSubtractionKeepCurrency']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('1 / 1 (100%)', $result['stdout']);

        $after = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($after);

        self::assertSame($shaBefore, $after->recordedSha('main'));
        self::assertSame($edgesBefore, self::allEdges($after));

        $testId = 'App\Tests\MoneyTest::testAdditionAndSubtractionKeepCurrency';
        $updated = $after->result('main', $testId);
        self::assertNotNull($updated);
        self::assertSame(0, $updated['status']);

        // Bug fix: this used to still refresh the filtered test's OWN cached result (its
        // `time`, specifically) even though nothing else changed — `recordsEdges: false`
        // only ever gated edge writing, never result merging. A CLI selection now
        // persists nothing at all: every result, including the filtered test's own, is
        // byte-for-byte what the full `record` above wrote.
        self::assertSame($before->results('main'), $after->results('main'));
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
