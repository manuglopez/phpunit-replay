<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * fix/monotonic-edges: a coverage driver only ever reports a file's declaration
 * footprint (class/enum/const, or any top-level statement — Record\Recorder's own
 * docblock) against whichever test in that process happened to load it first. Before
 * this fix, `GraphUpdater::apply()` folded a re-record's edges into the graph via
 * `Graph::replaceEdges()`, which *replaces* a re-recorded test's edge set outright — so a
 * partial re-record whose attribution differs from a previous, complete one could
 * silently drop a dependency the test still genuinely has. Its content key (ContentKey,
 * SPEC.md §4.3) then no longer includes that file's hash, so editing the file never
 * changes the key, so a `run` replays the cached (now stale) result instead of executing
 * the test — a false green.
 *
 * This reproduces the whole chain against the real `plain` fixture, reusing the fact
 * Scenario03SourceChangeTest establishes: every test file in
 * tests/Fixtures/Projects/plain that constructs/manipulates Money objects (CartTest
 * included) really depends on src/Money.php.
 *
 *  1. a full `record` proves tests/CartTest.php really depends on src/Money.php;
 *  2. a second, real `GraphUpdater::apply()` call — exactly the code path a partial
 *     re-record with a different worker/order attribution goes through — re-records
 *     CartTest.php's own results without ever mentioning src/Money.php;
 *  3. src/Money.php's content genuinely changes;
 *  4. a real `run` must still execute CartTest.php, not replay it from cache.
 */
final class MonotonicEdgesRegressionTest extends TestCase
{
    /** @var list<string> the same fixture facts Scenario03SourceChangeTest establishes */
    private const MONEY_DEPENDENTS = [
        'tests/CartTest.php',
        'tests/CommentedTest.php',
        'tests/DependsTest.php',
        'tests/DiscountTest.php',
        'tests/MoneyTest.php',
        'tests/TaxCalculatorTest.php',
    ];

    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_partial_re_record_does_not_drop_an_edge_a_full_record_already_proved(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertContains('src/Money.php', $graph->dependenciesOf('tests/CartTest.php'));

        $expectedTests = self::countResultsInFiles($graph, self::MONEY_DEPENDENTS);
        self::assertGreaterThan(0, $expectedTests);

        $this->reRecordCartTestWithoutMoney($graph);

        // Steps 1+2 of the false-green chain, at the integration level: the exact
        // GraphUpdater code path a partial re-record goes through must not have dropped
        // the edge just because this pass's own partial never mentioned it.
        self::assertContains(
            'src/Money.php',
            $graph->dependenciesOf('tests/CartTest.php'),
            'the defect: a partial re-record silently dropped a dependency a full record already proved',
        );

        // Step 3: Money.php genuinely changes.
        $this->fixture->applyVariant('Money.behaviour.php', 'src/Money.php');

        // Step 4: a real run must execute every Money.php dependent, CartTest.php
        // included — not replay it as a stale cached pass.
        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(
            $expectedTests,
            ReplayAssert::executedCount($result['stdout']),
            'false green: CartTest.php was replayed from cache instead of executed even though src/Money.php changed',
        );
    }

    /** @param list<string> $files */
    private static function countResultsInFiles(Graph $graph, array $files): int
    {
        $count = 0;
        $set = array_fill_keys($files, true);

        foreach ($graph->results('main') as $result) {
            if (isset($set[$result['file'] ?? ''])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Simulates exactly what a real partial re-record of tests/CartTest.php would look
     * like if, this time, a different test's process happened to load src/Money.php
     * first: CartTest.php's own results are unchanged (it did not itself change), but the
     * coverage this pass attributes to it omits src/Money.php — through the real
     * `GraphUpdater::apply()` production code path, not a hand-built graph state.
     */
    private function reRecordCartTestWithoutMoney(Graph $graph): void
    {
        $cartResults = [];

        foreach ($graph->results('main') as $testId => $result) {
            if (($result['file'] ?? null) === 'tests/CartTest.php') {
                $cartResults[$testId] = $result;
            }
        }

        self::assertNotSame([], $cartResults, 'fixture assumption: CartTest.php has recorded results');

        $withoutMoney = array_values(array_diff(
            $graph->dependenciesOf('tests/CartTest.php'),
            ['src/Money.php'],
        ));

        $root = $this->fixture->root();

        $partial = new RunPartial(
            edges: ['tests/CartTest.php' => $withoutMoney],
            results: $cartResults,
            tables: [],
            meta: [],
        );

        (new GraphUpdater($graph, $root, new ContentKey($root)))
            ->apply($partial, 'main', recordsEdges: true, complete: true);

        (new GraphStore(ReplayAssert::stateDir($this->fixture), $root))->save($graph);
    }
}
