<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay prune` (SPEC.md §11): `--flaky` (quarantine), `--branches` (baselines
 * of branches git no longer knows), `--all` (wipe the state directory), `--stale-edges`
 * (a dependency edge whose file is gone, inside a test file that still exists), and the
 * no-flag default (deleted test files, plus `--branches`).
 */
final class PruneCommandTest extends TestCase
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

    public function test_branches_removes_the_baseline_of_a_branch_git_no_longer_knows(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->fixture->repo->checkout('feature', true);
        $this->fixture->repo->git('commit', '--allow-empty', '-q', '-m', 'feature commit');

        $onFeature = $this->fixture->replay(['record']);
        self::assertSame(0, $onFeature['exitCode'], $onFeature['stdout'] . $onFeature['stderr']);

        $graphBefore = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphBefore);
        self::assertContains('feature', $graphBefore->branches());

        $this->fixture->repo->checkout('main');
        $this->fixture->repo->git('branch', '-D', 'feature');

        $pruned = $this->fixture->replay(['prune', '--branches']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertStringContainsString('feature', $pruned['stdout']);

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);
        self::assertNotContains('feature', $graphAfter->branches());

        $status = $this->fixture->replay(['status']);
        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringNotContainsString('feature', $status['stdout']);
    }

    public function test_all_empties_the_state_directory(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $stateDir = ReplayAssert::stateDir($this->fixture);
        self::assertFileExists($stateDir . '/graph.json');

        $pruned = $this->fixture->replay(['prune', '--all']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertFileDoesNotExist($stateDir . '/graph.json');

        $status = $this->fixture->replay(['status']);
        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('no baseline yet', $status['stdout']);
    }

    public function test_no_flag_prunes_results_of_a_test_file_deleted_from_disk(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graphBefore = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphBefore);
        self::assertTrue($graphBefore->knowsTest('tests/DiscountTest.php'));

        // Deleted from disk but not committed: prune inspects the filesystem, not git.
        $this->fixture->delete('tests/DiscountTest.php');

        $pruned = $this->fixture->replay(['prune']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertStringContainsString('pruned', $pruned['stdout']);

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);
        self::assertFalse($graphAfter->knowsTest('tests/DiscountTest.php'));

        foreach ($graphAfter->results('main') as $testId => $result) {
            self::assertNotSame('tests/DiscountTest.php', $result['file'] ?? null, $testId . ' should have been pruned');
        }
    }

    public function test_stale_edges_removes_a_dependency_edge_whose_file_is_gone(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graphBefore = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphBefore);
        self::assertContains('src/Greeter.php', $graphBefore->dependenciesOf('tests/GreeterTest.php'));

        // Deleted from disk but not committed: --stale-edges inspects the filesystem, not
        // git, exactly like the no-flag default already does for whole test files.
        $this->fixture->delete('src/Greeter.php');

        $pruned = $this->fixture->replay(['prune', '--stale-edges']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertStringContainsString('stale dependency edge', $pruned['stdout']);

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);
        self::assertNotContains('src/Greeter.php', $graphAfter->dependenciesOf('tests/GreeterTest.php'));

        // The test file itself is untouched: only the one stale dependency edge is gone.
        self::assertTrue($graphAfter->knowsTest('tests/GreeterTest.php'));
    }

    /**
     * Adversarial case (a "looks stale under a filtered run" edge that must survive):
     * an incremental replay after an UNRELATED change never touches CartTest at all
     * (Greeter has no dependents in common with Cart), so CartTest's edges go
     * unrefreshed by that pass. `--stale-edges` must not read that as staleness — only a
     * dependency confirmed gone from disk is ever removed.
     */
    public function test_stale_edges_keeps_a_live_dependency_not_touched_by_the_last_replay(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graphBefore = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphBefore);
        $cartDepsBefore = $graphBefore->dependenciesOf('tests/CartTest.php');
        self::assertContains('src/Cart.php', $cartDepsBefore);
        self::assertContains('src/Money.php', $cartDepsBefore);
        self::assertContains('src/TaxCalculator.php', $cartDepsBefore);

        $this->fixture->write('src/Greeter.php', $this->fixture->read('src/Greeter.php') . "\n// unrelated tweak\n");

        $incremental = $this->fixture->replay([]);
        self::assertSame(0, $incremental['exitCode'], $incremental['stdout'] . $incremental['stderr']);
        self::assertStringNotContainsString('CartTest', $incremental['stdout']);

        $pruned = $this->fixture->replay(['prune', '--stale-edges']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);
        self::assertEqualsCanonicalizing($cartDepsBefore, $graphAfter->dependenciesOf('tests/CartTest.php'));
    }

    public function test_bare_prune_does_not_remove_stale_dependency_edges(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->fixture->delete('src/Greeter.php');

        // Opt-in only: --stale-edges is not part of the no-flag default bundle, unlike
        // pruneMissingTestFiles()/--branches (higher stakes: a wrongly-dropped edge is
        // exactly what would reintroduce the false green Graph::unionEdges() fixed).
        $pruned = $this->fixture->replay(['prune']);
        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);
        self::assertContains('src/Greeter.php', $graphAfter->dependenciesOf('tests/GreeterTest.php'));
    }
}
