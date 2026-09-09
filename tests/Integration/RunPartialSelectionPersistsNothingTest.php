<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * The false-green vector reported alongside the `hasPartialSelection()` XML-contamination
 * defect: on a real project shaped like this fixture — `phpunit.xml` excludes an opt-in
 * group of tests that hit a real, paid third-party API — `run --group <that-group>` used
 * to still fold that group's results into the graph (`GraphUpdater::apply()`'s
 * `recordsEdges: false` only ever gated EDGE writing, never result merging), so a later
 * ordinary `run` could replay a real API's cached response instead of ever calling it
 * again. `record` never executes that group at all (the XML exclude governs its own
 * "full suite" run), so the entry `run --group <that-group>` created was not a refresh of
 * something the project's own baseline already vouched for — it was new, cache-worthy
 * state a CLI selection alone had no business writing.
 *
 * A CLI selection must now persist NOTHING: no edges (already true before this fix), and —
 * the part that was missing — no results, no baseline sha change, no stats change either.
 */
final class RunPartialSelectionPersistsNothingTest extends TestCase
{
    private const LIVE_GROUP = 'live-ai';

    private const LIVE_TEST_ID = 'App\Tests\LiveAiTest::testCallsARealPaidApi';

    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();

        // A test file with one method in an XML-excluded group, alongside an ordinary one —
        // the real-world shape: an opt-in group tagging individual methods inside test
        // classes that otherwise participate normally in the recorded suite.
        $this->fixture->write('tests/LiveAiTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\Attributes\Group;
            use PHPUnit\Framework\TestCase;

            final class LiveAiTest extends TestCase
            {
                #[Group('live-ai')]
                public function testCallsARealPaidApi(): void
                {
                    self::assertTrue(true);
                }

                public function testOrdinaryAssertion(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        $xml = $this->fixture->read('phpunit.xml');
        $withExclude = str_replace(
            '</testsuites>',
            "</testsuites>\n    <groups>\n        <exclude>\n            <group>" . self::LIVE_GROUP . "</group>\n        </exclude>\n    </groups>",
            $xml,
        );
        self::assertNotSame($xml, $withExclude);
        $this->fixture->write('phpunit.xml', $withExclude);

        $this->fixture->repo->commitAll('add an XML-excluded live-ai group');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_record_never_touches_the_excluded_group_and_a_cli_selected_run_of_it_persists_nothing(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $baseline = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($baseline);

        // `record` respects the project's own XML exclude: the live-ai test never ran, and
        // its id is nowhere in the baseline — the fix's rule 1 half.
        self::assertNull($baseline->result('main', self::LIVE_TEST_ID));

        $shaBefore = $baseline->recordedSha('main');
        $statsBefore = $baseline->stats();
        $edgesBefore = self::allEdges($baseline);
        $resultsBefore = $baseline->results('main');

        // A deliberate CLI selection: the user explicitly asks to run the excluded group.
        // PHPUnit really executes it (this is not blocked), but nothing it observes may
        // enter the cache — rule 2's half.
        $result = $this->fixture->replay(['--', '--group', self::LIVE_GROUP]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('1 / 1 (100%)', $result['stdout']);

        $after = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($after);

        // The one assertion that matters most: the live-ai test's result never entered the
        // graph at all — not merged, not refreshed, not present. The next test in this
        // class shows the same rule holding for an already-known file/group too.
        self::assertNull($after->result('main', self::LIVE_TEST_ID));

        // Nothing else changed either: no edges, no baseline sha, no stats, no other result.
        self::assertSame($shaBefore, $after->recordedSha('main'));
        self::assertSame($statsBefore, $after->stats());
        self::assertSame($edgesBefore, self::allEdges($after));
        self::assertSame($resultsBefore, $after->results('main'));
    }

    /**
     * The sibling, already-known-file scenario: an ordinary group (never excluded) whose
     * file IS already part of the baseline. A CLI selection of just that group must still
     * persist nothing — the false-green vector matters most for the excluded-group case
     * above, but rule 2 is unconditional, not gated on whether the file happens to be new.
     */
    public function test_a_cli_selected_run_of_an_ordinary_already_known_group_also_persists_nothing(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $before = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($before);
        $ordinaryId = 'App\Tests\LiveAiTest::testOrdinaryAssertion';
        $ordinaryBefore = $before->result('main', $ordinaryId);
        self::assertNotNull($ordinaryBefore);

        $shaBefore = $before->recordedSha('main');
        $statsBefore = $before->stats();
        $edgesBefore = self::allEdges($before);
        $resultsBefore = $before->results('main');

        // "default" is the fixture's only, ordinary, non-excluded group (PHPUnit assigns
        // every test to it implicitly): a CLI selection of it still means "run exactly
        // this," even though every one of its tests is already part of the baseline.
        $result = $this->fixture->replay(['--', '--group', 'default']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        $after = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($after);

        self::assertSame($shaBefore, $after->recordedSha('main'));
        self::assertSame($statsBefore, $after->stats());
        self::assertSame($edgesBefore, self::allEdges($after));
        self::assertSame($resultsBefore, $after->results('main'));
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
