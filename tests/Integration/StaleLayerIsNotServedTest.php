<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * A branch's own result layer is recorded against the tree at its own sha. When the pass
 * diffs against a DIFFERENT baseline (a nearer branch, docs/DECISIONS.md D-039), the diff
 * says nothing about the own layer: a test the own layer ran on code the branch has since
 * reverted must not be served from it just because the other baseline's diff does not
 * select it (`Select\LayerAudit`).
 *
 *   main ──● recorded
 *          ├── develop ──● breaks Money::add            (develop's layer: 5 failures)
 *          └── feature ──● A: benign Money edit, run    (feature's layer: passes at A)
 *                        ──● revert A ──● merge develop (tree == develop's tree)
 *
 * On the merged tree `develop` is 0 files away and wins, the diff against it is empty, and
 * before the fix the feature layer's passes (recorded on A's Money) overrode develop's
 * failures: `✓ 0 executed · 35 replayed`, exit 0, while PHPUnit itself failed 5 tests.
 */
final class StaleLayerIsNotServedTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();

        $recorded = $this->fixture->replay(['record'], $this->env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_revert_then_merge_does_not_serve_the_branch_layer_recorded_before_it(): void
    {
        $repo = $this->fixture->repo;

        $repo->checkout('develop', create: true);
        $this->breakMoneyAdd();
        $repo->commitAll('develop breaks Money::add');

        $repo->checkout('main');
        $repo->checkout('feature', create: true);
        $this->fixture->applyVariant('Money.behaviour.php', 'src/Money.php');
        $repo->commitAll('A: benign Money change');

        $onFeature = $this->fixture->replay([], $this->env());
        self::assertSame(0, $onFeature['exitCode'], $onFeature['stdout'] . $onFeature['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($onFeature['stdout']), ReplayAssert::lastLine($onFeature['stdout']));

        $repo->checkout('develop');
        $onDevelop = $this->fixture->replay([], $this->env());
        self::assertSame(1, $onDevelop['exitCode'], $onDevelop['stdout'] . $onDevelop['stderr']);

        $repo->checkout('feature');
        $repo->git('revert', '--no-edit', 'HEAD');
        $repo->git('merge', '--no-edit', '-q', 'develop');
        self::assertSame('', $repo->git('diff', 'develop', 'HEAD'), 'the merged tree is develop\'s tree');

        $phpunit = $this->fixture->phpunit();
        self::assertSame(1, $phpunit['exitCode'], 'control: PHPUnit itself fails on this tree');

        // The plan is taken before the run changes anything, but asserted after it: the exit
        // code is the false green itself, and must be what fails first without the fix.
        $dryRun = $this->fixture->replay(['run', '--dry-run'], $this->env());

        $run = $this->fixture->replay([], $this->env());
        self::assertSame(1, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));

        self::assertStringContainsString('baseline develop@', $dryRun['stdout']);
        self::assertDoesNotMatchRegularExpression('/\b0 test files would run/', $dryRun['stdout'], $dryRun['stdout']);
        // The plan says why: the feature layer's result was recorded before the revert.
        self::assertMatchesRegularExpression('#tests/MoneyTest\.php\s+← StaleLayer src/Money\.php \(feature@[0-9a-f]{7}\)#u', $dryRun['stdout'], $dryRun['stdout']);

        // And the next pass, now that the branch's own baseline sits on this very tree and
        // wins again: the stale passes must not come back from the own layer either.
        $again = $this->fixture->replay([], $this->env());
        self::assertSame(1, $again['exitCode'], $again['stdout'] . $again['stderr']);
    }

    public function test_reverting_to_the_default_branch_does_not_serve_the_fix_it_reverted(): void
    {
        $repo = $this->fixture->repo;

        // main breaks Money::add and records it: main's layer holds the failures.
        $this->breakMoneyAdd();
        $repo->commitAll('main breaks Money::add');
        $onMain = $this->fixture->replay([], $this->env());
        self::assertSame(1, $onMain['exitCode'], $onMain['stdout'] . $onMain['stderr']);

        // feature fixes it and runs: its own layer holds the passes, recorded on the fix.
        $repo->checkout('feature', create: true);
        $this->fixture->write('src/Money.php', str_replace('$this->amount - $other->amount + 0', '$this->amount + $other->amount', $this->fixture->read('src/Money.php')));
        $repo->commitAll('A: fix Money::add');
        $fixed = $this->fixture->replay([], $this->env());
        self::assertSame(0, $fixed['exitCode'], $fixed['stdout'] . $fixed['stderr']);

        // Reverting the fix puts the tree back on main's, 0 files from main: main wins over
        // the branch's own baseline (1 file away), and its diff is empty.
        $repo->git('revert', '--no-edit', 'HEAD');

        $phpunit = $this->fixture->phpunit();
        self::assertSame(1, $phpunit['exitCode'], 'control: PHPUnit itself fails on this tree');

        $run = $this->fixture->replay([], ['CI' => '']);
        self::assertSame(1, $run['exitCode'], $run['stdout'] . $run['stderr']);
    }

    public function test_the_own_layer_is_still_served_when_its_diff_does_not_touch_it(): void
    {
        // Positive control: auditing the own layer against its own diff must not turn a
        // nearer-baseline pass into a mass re-execution. feature is cut from develop and
        // runs (its layer: GreeterTest); develop moves on with three files feature never
        // touched; feature merges it. develop (2 files away: Greeter, GreeterTest) wins over
        // feature's own baseline (3 files away), only GreeterTest is selected, and the own
        // layer's diff (develop's three files) touches nothing the own layer holds.
        $repo = $this->fixture->repo;

        $repo->checkout('develop', create: true);
        $this->fixture->write('src/DevelopOnly.php', self::emptyClass('DevelopOnly'));
        $repo->commitAll('develop starts');
        $onDevelop = $this->fixture->replay([], $this->env());
        self::assertSame(0, $onDevelop['exitCode'], $onDevelop['stdout'] . $onDevelop['stderr']);

        $repo->checkout('feature', create: true);
        $this->fixture->write('src/Greeter.php', str_replace("'Hello'", "'Hi'", $this->fixture->read('src/Greeter.php')));
        $this->fixture->write('tests/GreeterTest.php', str_replace(['Hello', 'HELLO'], ['Hi', 'HI'], $this->fixture->read('tests/GreeterTest.php')));
        $repo->commitAll('feature: Greeter says Hi');
        $onFeature = $this->fixture->replay([], $this->env());
        self::assertSame(0, $onFeature['exitCode'], $onFeature['stdout'] . $onFeature['stderr']);
        $greeterTests = ReplayAssert::executedCount($onFeature['stdout']);
        self::assertGreaterThan(0, $greeterTests, ReplayAssert::lastLine($onFeature['stdout']));

        $repo->checkout('develop');
        $this->fixture->applyVariant('Money.behaviour.php', 'src/Money.php');
        $this->fixture->write('src/ExtraOne.php', self::emptyClass('ExtraOne'));
        $this->fixture->write('src/ExtraTwo.php', self::emptyClass('ExtraTwo'));
        $repo->commitAll('develop moves on');
        $moved = $this->fixture->replay([], $this->env());
        self::assertSame(0, $moved['exitCode'], $moved['stdout'] . $moved['stderr']);

        $repo->checkout('feature');
        $repo->git('merge', '--no-edit', '-q', 'develop');

        $dryRun = $this->fixture->replay(['run', '--dry-run'], $this->env());
        self::assertStringContainsString('baseline develop@', $dryRun['stdout'], $dryRun['stdout']);
        self::assertStringNotContainsString('StaleLayer', $dryRun['stdout'], $dryRun['stdout']);

        $run = $this->fixture->replay([], $this->env());
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame($greeterTests, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));
        self::assertSame(35 - $greeterTests, ReplayAssert::replayedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));
    }

    public function test_in_process_an_own_layer_recorded_without_a_sha_is_not_served(): void
    {
        // In-process the pass always diffs from the branch's own sha, or the default
        // branch's while it has none. Under CI a pass saves its results but publishes no
        // sha (SPEC.md §12.1), so the own layer sits ABOVE the diff base with nothing to
        // check it by: here it holds the passes of a fix the branch then reverted.
        $fixture = FixtureProject::inprocess();

        try {
            $this->breakMoneyAdd($fixture);
            $fixture->repo->commitAll('main breaks Money::add');
            $recorded = $fixture->phpunitInProcess([], ['CI' => '']);
            self::assertSame(1, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

            $fixture->repo->checkout('feature', create: true);
            $fixture->write('src/Money.php', str_replace('$this->amount - $other->amount + 0', '$this->amount + $other->amount', $fixture->read('src/Money.php')));
            $fixture->repo->commitAll('A: fix Money::add');
            $fixed = $fixture->phpunitInProcess([], ['CI' => 'true']);
            self::assertSame(0, $fixed['exitCode'], $fixed['stdout'] . $fixed['stderr']);

            $fixture->repo->git('revert', '--no-edit', 'HEAD');

            $again = $fixture->phpunitInProcess([], ['CI' => 'true']);
            self::assertSame(1, $again['exitCode'], $again['stdout'] . $again['stderr']);
        } finally {
            $fixture->destroy();
        }
    }

    public function test_an_own_layer_recorded_without_a_sha_is_not_served_by_the_wrapper_either(): void
    {
        $repo = $this->fixture->repo;

        $this->breakMoneyAdd();
        $repo->commitAll('main breaks Money::add');
        $onMain = $this->fixture->replay([], $this->env());
        self::assertSame(1, $onMain['exitCode'], $onMain['stdout'] . $onMain['stderr']);

        $repo->checkout('feature', create: true);
        $this->fixture->write('src/Money.php', str_replace('$this->amount - $other->amount + 0', '$this->amount + $other->amount', $this->fixture->read('src/Money.php')));
        $repo->commitAll('A: fix Money::add');
        $fixed = $this->fixture->replay([], ['CI' => 'true']);
        self::assertSame(0, $fixed['exitCode'], $fixed['stdout'] . $fixed['stderr']);

        $repo->git('revert', '--no-edit', 'HEAD');

        // Plan taken first, asserted last: the exit code is what must fail without the fix.
        $dryRun = $this->fixture->replay(['run', '--dry-run'], ['CI' => 'true']);

        $again = $this->fixture->replay([], ['CI' => 'true']);
        self::assertSame(1, $again['exitCode'], $again['stdout'] . $again['stderr']);

        self::assertMatchesRegularExpression('#tests/MoneyTest\.php\s+← StaleLayer feature \(no recorded sha\)#u', $dryRun['stdout'], $dryRun['stdout']);
    }

    public function test_a_default_layer_that_moved_on_below_a_present_nearest_baseline_is_not_audited(): void
    {
        // Graph::fallbackChain()'s argument, exercised: below a baseline whose layer this
        // graph holds, a fallback layer recorded on a DIFFERENT tree is still safe to read.
        // Here main moves on and breaks Money::add after develop was cut; feature (from
        // develop, whose layer is empty: its one pass served everything from main as valid)
        // reads main's newer layer. The failures it finds there can only force a re-run —
        // they cannot mask one — and the re-run passes, as PHPUnit does on this tree.
        $repo = $this->fixture->repo;

        $repo->checkout('develop', create: true);
        $this->fixture->write('src/DevelopOnly.php', self::emptyClass('DevelopOnly'));
        $repo->commitAll('develop starts');
        $onDevelop = $this->fixture->replay([], $this->env());
        self::assertSame(0, $onDevelop['exitCode'], $onDevelop['stdout'] . $onDevelop['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($onDevelop['stdout']), ReplayAssert::lastLine($onDevelop['stdout']));

        $repo->checkout('main');
        $this->breakMoneyAdd();
        $repo->commitAll('main moves on and breaks Money::add');
        $onMain = $this->fixture->replay([], $this->env());
        self::assertSame(1, $onMain['exitCode'], $onMain['stdout'] . $onMain['stderr']);

        $repo->checkout('develop');
        $repo->checkout('feature', create: true);

        $phpunit = $this->fixture->phpunit();
        self::assertSame(0, $phpunit['exitCode'], 'control: this tree is green');

        $dryRun = $this->fixture->replay(['run', '--dry-run'], $this->env());
        self::assertStringContainsString('baseline develop@', $dryRun['stdout'], $dryRun['stdout']);
        self::assertStringNotContainsString('StaleLayer', $dryRun['stdout'], $dryRun['stdout']);
        self::assertStringContainsString('← Rerun', $dryRun['stdout'], $dryRun['stdout']);

        $run = $this->fixture->replay([], $this->env());
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));
    }

    private function breakMoneyAdd(?FixtureProject $fixture = null): void
    {
        $fixture ??= $this->fixture;

        $fixture->write('src/Money.php', str_replace(
            'return new self($this->amount + $other->amount, $this->currency);',
            'return new self($this->amount - $other->amount + 0, $this->currency);',
            $fixture->read('src/Money.php'),
        ));
    }

    private static function emptyClass(string $name): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class " . $name . "\n{\n}\n";
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'PHPUNIT_REPLAY_BASELINE_BRANCHES' => 'develop,main',
            'CI' => '',
        ];
    }
}
