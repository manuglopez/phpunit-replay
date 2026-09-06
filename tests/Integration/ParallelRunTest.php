<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §13: `--parallel`/`-p` runs the same filtered configuration through
 * `vendor/bin/paratest` instead of `vendor/bin/phpunit`, merging its `worker-<TEST_TOKEN>-*`
 * partials back together (see {@see \Manuglopez\Replay\Record\RunPartial}). Skipped entirely
 * when this package's own `vendor/bin/paratest` is not installed (`composer install --no-dev`).
 *
 * Mirrors Scenario01 (first run records), Scenario02 (no changes replays everything) and
 * Scenario03 (a source change re-executes exactly its dependents) with `-p 2` added, so any
 * divergence from the sequential path shows up directly against known-good numbers.
 */
final class ParallelRunTest extends TestCase
{
    /** @var list<FixtureProject> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! FixtureProject::paratestAvailable()) {
            self::markTestSkipped('vendor/bin/paratest is not installed in this package (composer install --no-dev?).');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        parent::tearDown();
    }

    public function test_record_parallel_records_the_same_baseline_as_sequential(): void
    {
        $sequential = $this->fixture();
        $parallel = $this->fixture();

        $seqResult = $sequential->replay(['record']);
        self::assertSame(0, $seqResult['exitCode'], $seqResult['stdout'] . $seqResult['stderr']);

        $parResult = $parallel->replay(['record', '-p', '2']);
        self::assertSame(0, $parResult['exitCode'], $parResult['stdout'] . $parResult['stderr']);
        self::assertStringStartsWith(
            'Replay  ● recorded 35 tests in 7 test files',
            ReplayAssert::lastLine($parResult['stdout']),
        );

        $seqGraph = ReplayAssert::loadGraph($sequential);
        $parGraph = ReplayAssert::loadGraph($parallel);
        self::assertNotNull($seqGraph);
        self::assertNotNull($parGraph);

        self::assertCount(35, $parGraph->results('main'));
        self::assertSame(count($seqGraph->results('main')), count($parGraph->results('main')));

        self::assertTrue($parGraph->knowsTest('tests/CartTest.php'));

        $sequentialCartEdges = $seqGraph->dependenciesOf('tests/CartTest.php');
        $parallelCartEdges = $parGraph->dependenciesOf('tests/CartTest.php');
        sort($sequentialCartEdges);
        sort($parallelCartEdges);
        self::assertSame($sequentialCartEdges, $parallelCartEdges);
        self::assertContains('src/Money.php', $parallelCartEdges);
    }

    public function test_no_changes_after_a_parallel_record_replays_everything_and_cleans_runs_dir(): void
    {
        $fixture = $this->fixture();

        $recorded = $fixture->replay(['record', '-p', '2']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $result = $fixture->replay(['-p', '2']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString(
            '0 executed (0 affected, 0 uncached) · 35 replayed',
            ReplayAssert::lastLine($result['stdout']),
        );

        self::assertSame([], glob(ReplayAssert::stateDir($fixture) . '/runs/*', GLOB_ONLYDIR) ?: []);
    }

    public function test_source_change_after_a_parallel_record_executes_only_its_dependents(): void
    {
        $fixture = $this->fixture();

        $recorded = $fixture->replay(['record', '-p', '2']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $fixture->applyVariant('Money.behaviour.php', 'src/Money.php');

        $result = $fixture->replay(['-p', '2']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        // affected counts tests, not files: all 31 executed tests are in the 6 files
        // Money.php's PhpEdge selected (docs/INTERNALS.md "Summary counters").
        self::assertStringContainsString(
            '31 executed (31 affected, 0 uncached) · 4 replayed',
            ReplayAssert::lastLine($result['stdout']),
        );

        self::assertSame([], glob(ReplayAssert::stateDir($fixture) . '/runs/*', GLOB_ONLYDIR) ?: []);
    }

    private function fixture(): FixtureProject
    {
        return $this->fixtures[] = FixtureProject::plain();
    }
}
