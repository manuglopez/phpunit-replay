<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Selector;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * Reproduces the defect measured on a real Laravel suite: a shared abstract base class
 * declares the test methods, and `final` concrete subclasses extend it adding none of
 * their own. `PHPUnit\Event\Code\TestMethod::file()` resolves through
 * `ReflectionMethod::getFileName()`, which returns the file that DECLARES an inherited
 * method — the abstract base — never the file of the class actually running it. Before
 * the fix, every one of these subscribers' five `$test->file()` call sites credited the
 * abstract base instead of the concrete subclass: the base (which PHPUnit never runs a
 * test against) accumulated every edge, and the concrete files that actually ran stayed
 * permanently unknown to the graph.
 *
 * Two variants, both reproduced here because they are not equivalent:
 *  - `SweepBaseTest` is abstract AND named with the configured test suffix — the shape
 *    actually measured, which also makes PHPUnit's own directory-based discovery try to
 *    load it as a test file and warn "declared ... is abstract" (a warning, never a
 *    failure — none of these fixtures set `failOnWarning`).
 *  - `SweepScenario` is abstract but named WITHOUT the test suffix — the natural fix for
 *    that warning, and the more common way an unrelated project would write a shared test
 *    base in the first place. The declaring-file defect survives that rename undiminished:
 *    it does not depend on the base's file matching any naming convention at all.
 *
 * Three properties matter for each variant, not just "edges exist":
 *  1. each concrete file's edge list is non-empty;
 *  2. that edge list contains the abstract base's OWN file — the base is source code the
 *     concrete tests depend on (its method bodies are what execute), so a change to it
 *     must be able to select them, exactly the way any other dependency does;
 *  3. the abstract base itself is never a key in `edges` — it runs no test, so it must
 *     never be treated as a test file the graph knows about.
 *
 * A end-to-end check on `Select\Selector` follows directly from (2): changing the
 * abstract base's file must select both concrete subclasses via `Select\Rules\PhpEdgeRule`,
 * which is the actual, user-visible consequence of (2) rather than a restatement of it.
 *
 * `ConcreteFiveTest` adds an empirical check for data providers: `SweepBaseTest::
 * testAlphaDoublesVarious()` is `#[DataProvider]`-driven, so `ConcreteFiveTest` inherits
 * several dataset repetitions of the same method, each recorded under its own `#dataset`
 * suffixed id (`PHPUnit\Event\Code\TestMethod::id()`) but all resolving to the same file.
 */
final class AbstractBaseTestClassEdgesTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::abstractBase();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_edges_are_credited_to_the_concrete_subclass_not_the_abstract_base(): void
    {
        $result = $this->fixture->replay(['record']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph, 'graph.json should exist and decode');

        // Every concrete test id recorded a result, keyed by test id — the results path
        // was already correct before this fix and must stay that way. 11 = 3 (One) + 3
        // (Two) + 3 (Five: plain + 2 dataset repetitions) + 1 (Three) + 1 (Four).
        self::assertTrue($graph->knowsTest('tests/ConcreteOneTest.php'));
        self::assertTrue($graph->knowsTest('tests/ConcreteTwoTest.php'));
        self::assertTrue($graph->knowsTest('tests/ConcreteThreeTest.php'));
        self::assertTrue($graph->knowsTest('tests/ConcreteFourTest.php'));
        self::assertTrue($graph->knowsTest('tests/ConcreteFiveTest.php'));
        self::assertCount(11, $graph->results('main'));
        self::assertArrayHasKey('App\Tests\ConcreteOneTest::testAlphaDoubles', $graph->results('main'));
        self::assertArrayHasKey('App\Tests\ConcreteTwoTest::testAlphaDoubles', $graph->results('main'));
        self::assertArrayHasKey('App\Tests\ConcreteThreeTest::testBetaAddsTen', $graph->results('main'));
        self::assertArrayHasKey('App\Tests\ConcreteFourTest::testBetaAddsTen', $graph->results('main'));
        self::assertArrayHasKey('App\Tests\ConcreteFiveTest::testAlphaDoubles', $graph->results('main'));

        // A data-provider dataset does not change which class is running
        // (TestMethodBuilder::dataFor() only attaches test data to the SAME instance), so
        // every repetition of the same inherited method must key its result the same way.
        self::assertArrayHasKey('App\Tests\ConcreteFiveTest::testAlphaDoublesVarious#small', $graph->results('main'));
        self::assertArrayHasKey('App\Tests\ConcreteFiveTest::testAlphaDoublesVarious#large', $graph->results('main'));

        // Variant 1 (SweepBaseTest: abstract, named with the test suffix).
        $this->assertConcreteFileOwnsItsEdges($graph, 'tests/ConcreteOneTest.php', 'tests/SweepBaseTest.php', 'src/Alpha.php');
        $this->assertConcreteFileOwnsItsEdges($graph, 'tests/ConcreteTwoTest.php', 'tests/SweepBaseTest.php', 'src/Alpha.php');
        $this->assertConcreteFileOwnsItsEdges($graph, 'tests/ConcreteFiveTest.php', 'tests/SweepBaseTest.php', 'src/Alpha.php');
        self::assertFalse(
            $graph->knowsTest('tests/SweepBaseTest.php'),
            'the abstract base runs no test and must never be a key in edges',
        );

        // Variant 2 (SweepScenario: abstract, named WITHOUT the test suffix).
        $this->assertConcreteFileOwnsItsEdges($graph, 'tests/ConcreteThreeTest.php', 'tests/SweepScenario.php', 'src/Beta.php');
        $this->assertConcreteFileOwnsItsEdges($graph, 'tests/ConcreteFourTest.php', 'tests/SweepScenario.php', 'src/Beta.php');
        self::assertFalse(
            $graph->knowsTest('tests/SweepScenario.php'),
            'the abstract base runs no test and must never be a key in edges',
        );

        // End-to-end: changing the abstract base's own file must select BOTH concrete
        // subclasses through Select\Rules\PhpEdgeRule — the actual consequence of the
        // base appearing in their dependency lists, not merely a restatement of it.
        $testPaths = new TestPaths(['tests'], [], ['Test.php']);
        $watch = new WatchPatterns();
        $watch->useDefaults($this->fixture->root(), ['tests']);
        $selector = Selector::default($graph, $testPaths, $watch, $this->fixture->root());

        $selectionOne = $selector->affected(['tests/SweepBaseTest.php']);
        self::assertTrue($selectionOne->has('tests/ConcreteOneTest.php'), 'changing the abstract base must select ConcreteOneTest');
        self::assertTrue($selectionOne->has('tests/ConcreteTwoTest.php'), 'changing the abstract base must select ConcreteTwoTest');

        $selectionTwo = $selector->affected(['tests/SweepScenario.php']);
        self::assertTrue($selectionTwo->has('tests/ConcreteThreeTest.php'), 'changing the abstract base must select ConcreteThreeTest');
        self::assertTrue($selectionTwo->has('tests/ConcreteFourTest.php'), 'changing the abstract base must select ConcreteFourTest');
    }

    private function assertConcreteFileOwnsItsEdges(
        Graph $graph,
        string $concreteFile,
        string $abstractBaseFile,
        string $expectedSourceDependency,
    ): void {
        $dependencies = $graph->dependenciesOf($concreteFile);

        self::assertNotSame([], $dependencies, sprintf('%s must have a non-empty edge list', $concreteFile));
        self::assertContains(
            $abstractBaseFile,
            $dependencies,
            sprintf(
                '%s must depend on %s (the file whose method body actually ran) so that changing it selects %s',
                $concreteFile,
                $abstractBaseFile,
                $concreteFile,
            ),
        );
        self::assertContains($expectedSourceDependency, $dependencies, sprintf('%s must still depend on %s', $concreteFile, $expectedSourceDependency));
    }
}
