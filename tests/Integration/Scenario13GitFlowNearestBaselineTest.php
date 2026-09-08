<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * DECISIONS.md D-039 / docs/INTERNALS.md "Nearest baseline", end to end over a git-flow
 * history:
 *
 *   main ──● (recorded)
 *          └── develop ──● behaviour change (recorded)
 *                        └── feature/checkout   → inherits develop
 *          └── hotfix/tax                       → inherits main
 *
 * The point of the feature branch inheriting `develop` rather than `main` is that
 * everything `develop` has already run stays replayed: diffing a feature branch against
 * `main` would re-run every test touched by every change `develop` has accumulated since
 * the last release.
 */
final class Scenario13GitFlowNearestBaselineTest extends TestCase
{
    private FixtureProject $fixture;

    private string $mainSha = '';

    private string $developSha = '';

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();

        $recorded = $this->fixture->replay(['record'], $this->env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        $this->mainSha = $this->fixture->repo->sha();

        $this->fixture->repo->checkout('develop', create: true);
        $this->fixture->applyVariant('Money.behaviour.php', 'src/Money.php');
        $this->developSha = $this->fixture->repo->commitAll('behaviour change on develop');

        $onDevelop = $this->fixture->replay([], $this->env());
        self::assertSame(0, $onDevelop['exitCode'], $onDevelop['stdout'] . $onDevelop['stderr']);
        self::assertSame(31, ReplayAssert::executedCount($onDevelop['stdout']), ReplayAssert::lastLine($onDevelop['stdout']));
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_feature_branch_inherits_the_develop_baseline(): void
    {
        $this->fixture->repo->checkout('feature/checkout', create: true);

        $status = $this->fixture->replay(['status'], $this->env());

        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('branch:    feature/checkout (default: main)', $status['stdout']);
        self::assertStringContainsString('baseline develop@' . substr($this->developSha, 0, 7) . ' (nearest,', $status['stdout']);
    }

    public function test_a_feature_branch_with_nothing_of_its_own_selects_nothing(): void
    {
        $this->fixture->repo->checkout('feature/checkout', create: true);

        $dryRun = $this->fixture->replay(['--dry-run'], $this->env());

        self::assertSame(0, $dryRun['exitCode'], $dryRun['stdout'] . $dryRun['stderr']);
        self::assertStringContainsString('baseline develop@' . substr($this->developSha, 0, 7), $dryRun['stdout']);
        self::assertStringContainsString('0 test files would run (0 affected, 0 uncached, 0 quarantined)', $dryRun['stdout']);

        // The whole suite replays: develop's 31 results layered over main's, plus the 4
        // main results develop never had to re-run (docs/INTERNALS.md "Nearest baseline").
        self::assertStringContainsString('35 tests would replay', $dryRun['stdout']);

        $run = $this->fixture->replay([], $this->env());

        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($run['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($run['stdout']));
        // `PHPUnit\Runner\Version::getVersionString()` is 'PHPUnit <id> by Sebastian Bergmann
        // and contributors.' on every supported major; asserting the stable, version-agnostic
        // half proves no PHPUnit banner was printed at all (i.e. no PHPUnit process ran),
        // without hard-coding a major that would go stale on the next supported release.
        self::assertStringNotContainsString('by Sebastian Bergmann and contributors', $run['stdout']);
    }

    public function test_a_hotfix_branch_cut_from_main_inherits_main(): void
    {
        $this->fixture->repo->checkout('main');
        $this->fixture->repo->checkout('hotfix/tax', create: true);

        $status = $this->fixture->replay(['status'], $this->env());

        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('baseline main@' . substr($this->mainSha, 0, 7) . ' (nearest,', $status['stdout']);

        // develop is not an ancestor of a hotfix cut from main, so its baseline is never
        // a candidate however preferred it is.
        self::assertStringNotContainsString('baseline develop@', $status['stdout']);

        $dryRun = $this->fixture->replay(['--dry-run'], $this->env());

        self::assertSame(0, $dryRun['exitCode'], $dryRun['stdout'] . $dryRun['stderr']);
        self::assertStringContainsString('0 test files would run', $dryRun['stdout']);
        self::assertStringContainsString('35 tests would replay', $dryRun['stdout']);
    }

    public function test_without_baseline_branches_a_feature_branch_falls_back_to_main(): void
    {
        $this->fixture->repo->checkout('feature/checkout', create: true);

        // No `baseline_branches`: `develop` is not a candidate at all, so the only usable
        // ancestor baseline is the default branch's — the phase 1/2 behaviour.
        $status = $this->fixture->replay(['status'], ['CI' => '']);

        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('baseline main@' . substr($this->mainSha, 0, 7), $status['stdout']);
        self::assertStringNotContainsString('baseline develop@', $status['stdout']);
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'PHPUNIT_REPLAY_BASELINE_BRANCHES' => 'develop,main',
            // The package's own suite may run under CI, where a pass refuses to publish a
            // branch baseline; this scenario is about the developer path.
            'CI' => '',
        ];
    }
}
