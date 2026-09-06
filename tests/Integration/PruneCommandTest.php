<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay prune` (SPEC.md §11): `--flaky` (quarantine), `--branches` (baselines
 * of branches git no longer knows), `--all` (wipe the state directory), and the
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
}
