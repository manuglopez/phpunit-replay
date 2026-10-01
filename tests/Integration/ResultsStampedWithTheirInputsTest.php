<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * A result layer used to be trusted as recorded on the tree at its own sha, and three ways
 * of writing results broke that premise without anything noticing (`Select\StampAudit`):
 *
 * - a run on a DIRTY working tree stores its results in the branch's layer at the clean sha;
 *   another branch cut from that sha reads them with an empty diff (only the last-run
 *   snapshot knew about the dirty content, and it describes one branch);
 * - a CI run without `--allow-ci-baseline` writes results into the layer but leaves its sha
 *   behind; a revert back to that sha makes the diff empty, and the results of the reverted
 *   tree are served — for an edge input and, what the content key cannot see, for a watched
 *   file the test reads;
 * - a layer recorded on a dirty tree is published with `remote_push: all`, and another
 *   machine, clean at that sha, adopts it.
 *
 * Each result now carries the content key and the non-edge input digest it was recorded
 * under, and is served only when both still match the current tree.
 */
final class ResultsStampedWithTheirInputsTest extends TestCase
{
    private const GOOD_ADD = 'return new self($this->amount + $other->amount, $this->currency);';

    private const BAD_ADD = 'return new self($this->amount - $other->amount + 0, $this->currency);';

    private ?FixtureProject $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->destroy();
    }

    public function test_results_recorded_on_a_dirty_tree_are_not_served_on_another_branch(): void
    {
        $fixture = $this->recordedPlain();
        $repo = $fixture->repo;

        $this->breakMoneyAdd($fixture);
        $repo->commitAll('main breaks Money::add');
        $broken = $fixture->replay([], self::env());
        self::assertSame(1, $broken['exitCode'], $broken['stdout'] . $broken['stderr']);

        // An uncommitted fix, run: main's layer now holds passes recorded on it, at the
        // (broken) committed sha.
        $this->fixMoneyAdd($fixture);
        $dirty = $fixture->replay([], self::env());
        self::assertSame(0, $dirty['exitCode'], $dirty['stdout'] . $dirty['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($dirty['stdout']), ReplayAssert::lastLine($dirty['stdout']));

        // The fix is discarded and a branch is cut from the committed sha.
        $repo->git('checkout', '--', 'src/Money.php');
        $repo->checkout('other', create: true);
        self::assertSame(1, $fixture->phpunit()['exitCode'], 'control: PHPUnit itself fails on this tree');

        // Plan taken first, asserted last: the exit code is what must fail without the fix.
        $dryRun = $fixture->replay(['run', '--dry-run'], self::env());

        $run = $fixture->replay([], self::env());
        self::assertSame(1, $run['exitCode'], 'false green: ' . $run['stdout'] . $run['stderr']);
        self::assertGreaterThan(0, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));

        self::assertMatchesRegularExpression('#tests/MoneyTest\.php\s+← StaleResult content key changed \(main@[0-9a-f]{7}\)#u', $dryRun['stdout'], $dryRun['stdout']);
    }

    public function test_in_process_results_recorded_on_a_dirty_tree_are_not_served_on_another_branch(): void
    {
        $fixture = $this->fixture = FixtureProject::inprocess();
        $repo = $fixture->repo;

        $recorded = $fixture->phpunitInProcess([], self::env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->breakMoneyAdd($fixture);
        $repo->commitAll('main breaks Money::add');
        $broken = $fixture->phpunitInProcess([], self::env());
        self::assertSame(1, $broken['exitCode'], $broken['stdout'] . $broken['stderr']);

        $this->fixMoneyAdd($fixture);
        $dirty = $fixture->phpunitInProcess([], self::env());
        self::assertSame(0, $dirty['exitCode'], $dirty['stdout'] . $dirty['stderr']);

        $repo->git('checkout', '--', 'src/Money.php');
        $repo->checkout('other', create: true);

        $run = $fixture->phpunitInProcess([], self::env());
        self::assertSame(1, $run['exitCode'], 'false green: ' . $run['stdout'] . $run['stderr']);
    }

    public function test_a_ci_run_that_left_the_sha_behind_is_not_served_after_a_revert(): void
    {
        $fixture = $this->recordedPlain();
        $repo = $fixture->repo;

        $this->breakMoneyAdd($fixture);
        $repo->commitAll('main breaks Money::add');
        $broken = $fixture->replay([], self::env());
        self::assertSame(1, $broken['exitCode'], $broken['stdout'] . $broken['stderr']);

        // CI runs the fix: results saved, the sha stays on the broken commit.
        $this->fixMoneyAdd($fixture);
        $repo->commitAll('fix Money::add');
        $ci = $fixture->replay([], ['CI' => 'true']);
        self::assertSame(0, $ci['exitCode'], $ci['stdout'] . $ci['stderr']);

        $repo->git('revert', '--no-edit', 'HEAD');
        self::assertSame(1, $fixture->phpunit()['exitCode'], 'control: PHPUnit itself fails on this tree');

        $run = $fixture->replay([], self::env());
        self::assertSame(1, $run['exitCode'], 'false green: ' . $run['stdout'] . $run['stderr']);
    }

    public function test_a_watched_file_a_ci_run_changed_is_not_served_after_a_revert(): void
    {
        // The variant only the non-edge digest can catch: the test reads a watched data file,
        // which is not among the files it executed, so its content key never moves. It is
        // recorded failing, so that the fix is not a flip under an unchanged key (quarantine).
        $fixture = $this->fixture = FixtureProject::plain();
        $repo = $fixture->repo;
        $fixture->write('tests/Fixtures/greeting.txt', "Bye\n");
        $fixture->write('tests/GreetingFixtureTest.php', self::greetingFixtureTest());
        $repo->commitAll('a test that reads a watched fixture, failing');

        $recorded = $fixture->replay(['record'], self::env());
        self::assertSame(1, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        // CI runs the fix: results saved, the sha stays on the failing commit.
        $fixture->write('tests/Fixtures/greeting.txt', "Hello\n");
        $repo->commitAll('the fixture is fixed');
        $ci = $fixture->replay([], ['CI' => 'true']);
        self::assertSame(0, $ci['exitCode'], $ci['stdout'] . $ci['stderr']);

        $repo->git('revert', '--no-edit', 'HEAD');
        self::assertSame(1, $fixture->phpunit()['exitCode'], 'control: PHPUnit itself fails on this tree');

        $dryRun = $fixture->replay(['run', '--dry-run'], self::env());

        $run = $fixture->replay([], self::env());
        self::assertSame(1, $run['exitCode'], 'false green: ' . $run['stdout'] . $run['stderr']);

        // The content key did not move: it is the digest that refused the result.
        self::assertMatchesRegularExpression('#tests/GreetingFixtureTest\.php\s+← StaleResult non-edge inputs changed \(main@[0-9a-f]{7}\)#u', $dryRun['stdout'], $dryRun['stdout']);
    }

    public function test_a_layer_recorded_on_a_dirty_tree_is_not_adopted_by_another_machine(): void
    {
        $shared = TempDir::make('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all', 'CI' => ''];
        $m1 = FixtureProject::plain(TempDir::make('m1') . '/shop');
        $m1->repo->git('remote', 'add', 'origin', 'https://example.invalid/acme/shop.git');
        $m2 = $m1->copyTo(TempDir::make('m2') . '/warehouse');

        try {
            $recorded = $m1->replay(['record'], $env);
            self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

            $this->breakMoneyAdd($m1);
            $m1->repo->commitAll('break Money::add');
            // An uncommitted fix, different from the original text.
            $m1->write('src/Money.php', str_replace(self::BAD_ADD, 'return new self($other->amount + $this->amount, $this->currency);', $m1->read('src/Money.php')));
            $dirty = $m1->replay([], $env);
            self::assertSame(0, $dirty['exitCode'], $dirty['stdout'] . $dirty['stderr']);

            $m2->repo->git('fetch', '-q', $m1->root(), 'main');
            $m2->repo->git('reset', '-q', '--hard', 'FETCH_HEAD');
            self::assertSame(1, $m2->phpunit()['exitCode'], 'control: the committed tree fails');

            $run = $m2->replay([], $env);
            self::assertSame(1, $run['exitCode'], 'false green: ' . $run['stdout'] . $run['stderr']);

            self::assertStringContainsString('working tree is dirty', $dirty['stderr'], 'the dirty run says why it did not publish its graph');
        } finally {
            $m1->destroy();
            $m2->destroy();
            TempDir::remove($shared);
        }
    }

    public function test_an_object_with_a_matching_digest_serves_a_file_a_watch_pattern_selected(): void
    {
        // An object now carries the non-edge digest its results ran under, so it can vouch for
        // a watched file too: another machine with the same edit is served from it, and one
        // with a different edit of the same file is not. Objects are still addressed by `k`
        // alone, so this needs a `k` the edit came with: a watched file changing on its own
        // leaves every `k` where it was, and the objects already there keep the digest they
        // were first published with (and refuse this tree, which is the safe answer).
        $shared = TempDir::make('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all', 'CI' => ''];
        $m1 = FixtureProject::plain(TempDir::make('m1') . '/shop');
        $m1->repo->git('remote', 'add', 'origin', 'https://example.invalid/acme/shop.git');
        $m1->write('tests/Fixtures/notes.txt', "one\n");
        $m1->repo->commitAll('a watched fixture');
        $m2 = $m1->copyTo(TempDir::make('m2') . '/warehouse');
        $m3 = $m1->copyTo(TempDir::make('m3') . '/depot');

        try {
            $recorded = $m1->replay(['record'], $env);
            self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

            // Both other machines start from that baseline, so their own diff will later select
            // every test through the watch pattern, and the objects are what they ask.
            foreach ([$m2, $m3] as $machine) {
                $adopted = $machine->replay([], $env);
                self::assertSame(35, ReplayAssert::replayedCount($adopted['stdout']), ReplayAssert::lastLine($adopted['stdout']));
            }

            // The watched fixture and Greeter change together: Greeter's dependents get a new
            // `k`, whose object m1 publishes with the digest of this very tree.
            $m1->write('tests/Fixtures/notes.txt', "two\n");
            $m1->write('src/Greeter.php', str_replace('$trimmed = trim($name);', '$trimmed = trim($name, " \t\n\r\0\x0B");', $m1->read('src/Greeter.php')));
            $m1->repo->commitAll('the watched fixture and Greeter change');
            $ran = $m1->replay([], $env);
            self::assertSame(0, $ran['exitCode'], $ran['stdout'] . $ran['stderr']);
            self::assertSame(35, ReplayAssert::executedCount($ran['stdout']), ReplayAssert::lastLine($ran['stdout']));

            $m2->repo->git('fetch', '-q', $m1->root(), 'main');
            $m2->repo->git('reset', '-q', '--hard', 'FETCH_HEAD');
            $served = $m2->replay([], $env);
            self::assertSame(0, $served['exitCode'], $served['stdout'] . $served['stderr']);
            self::assertMatchesRegularExpression('/\d+ replayed \([1-9]\d* from remote\)/', ReplayAssert::lastLine($served['stdout']));
            self::assertLessThan(35, ReplayAssert::executedCount($served['stdout']), ReplayAssert::lastLine($served['stdout']));

            $m3->repo->git('fetch', '-q', $m1->root(), 'main');
            $m3->repo->git('reset', '-q', '--hard', 'FETCH_HEAD');
            $m3->write('tests/Fixtures/notes.txt', "three\n");
            $refused = $m3->replay([], $env);
            self::assertSame(35, ReplayAssert::executedCount($refused['stdout']), ReplayAssert::lastLine($refused['stdout']));
        } finally {
            $m1->destroy();
            $m2->destroy();
            $m3->destroy();
            TempDir::remove($shared);
        }
    }

    public function test_a_no_change_run_still_replays_everything(): void
    {
        $fixture = $this->recordedPlain();

        $run = $fixture->replay([], self::env());
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));
    }

    public function test_an_edit_executes_only_what_it_affects(): void
    {
        $fixture = $this->recordedPlain();

        $fixture->write('src/Greeter.php', str_replace("'Hello'", "'Hi'", $fixture->read('src/Greeter.php')));
        $fixture->write('tests/GreeterTest.php', str_replace(['Hello', 'HELLO'], ['Hi', 'HI'], $fixture->read('tests/GreeterTest.php')));
        $fixture->repo->commitAll('Greeter says Hi');

        $run = $fixture->replay([], self::env());
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        $executed = ReplayAssert::executedCount($run['stdout']);
        self::assertGreaterThan(0, $executed, ReplayAssert::lastLine($run['stdout']));
        self::assertLessThan(35, $executed, ReplayAssert::lastLine($run['stdout']));
        self::assertSame(35 - $executed, ReplayAssert::replayedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));

        $again = $fixture->replay([], self::env());
        self::assertSame(0, ReplayAssert::executedCount($again['stdout']), ReplayAssert::lastLine($again['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($again['stdout']), ReplayAssert::lastLine($again['stdout']));
    }

    public function test_results_recorded_before_stamps_existed_execute_once_then_replay(): void
    {
        $fixture = $this->recordedPlain();
        self::stripDigests($fixture);

        $dryRun = $fixture->replay(['run', '--dry-run'], self::env());
        self::assertMatchesRegularExpression('#tests/MoneyTest\.php\s+← StaleResult unstamped \(main@[0-9a-f]{7}\)#u', $dryRun['stdout'], $dryRun['stdout']);

        $once = $fixture->replay([], self::env());
        self::assertSame(0, $once['exitCode'], $once['stdout'] . $once['stderr']);
        self::assertSame(35, ReplayAssert::executedCount($once['stdout']), ReplayAssert::lastLine($once['stdout']));

        $then = $fixture->replay([], self::env());
        self::assertSame(0, $then['exitCode'], $then['stdout'] . $then['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($then['stdout']), ReplayAssert::lastLine($then['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($then['stdout']), ReplayAssert::lastLine($then['stdout']));
    }

    public function test_a_file_with_a_numeric_name_at_the_root_is_hashed_like_any_other(): void
    {
        // `2024`, `404`: PHP turns a numeric string array key into an int, and every map
        // keyed by path handed one to a `string` parameter.
        $fixture = $this->recordedPlain();
        $fixture->write('2024', "notes\n");
        $fixture->repo->commitAll('a file named 2024');

        $recorded = $fixture->replay(['record'], self::env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $fixture->write('2024', "more notes\n");
        $fixture->write('404', "new\n");

        $dirty = $fixture->replay([], self::env());
        self::assertSame(0, $dirty['exitCode'], $dirty['stdout'] . $dirty['stderr']);
        self::assertStringNotContainsString('TypeError', $dirty['stdout'] . $dirty['stderr']);

        $again = $fixture->replay([], self::env());
        self::assertSame(0, $again['exitCode'], $again['stdout'] . $again['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($again['stdout']), ReplayAssert::lastLine($again['stdout']));
        self::assertSame(35, ReplayAssert::replayedCount($again['stdout']), ReplayAssert::lastLine($again['stdout']));
    }

    public function test_push_graph_refuses_a_dirty_tree_and_still_publishes_objects(): void
    {
        $shared = TempDir::make('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'off', 'CI' => ''];
        $fixture = $this->fixture = FixtureProject::plain();

        try {
            $recorded = $fixture->replay(['record'], $env);
            self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

            $fixture->write('src/Money.php', $fixture->read('src/Money.php') . "\n// uncommitted\n");

            $push = $fixture->replay(['push', '--graph'], $env);
            self::assertSame(1, $push['exitCode'], $push['stdout'] . $push['stderr']);
            self::assertStringContainsString('working tree is dirty', $push['stdout'] . $push['stderr']);
            self::assertMatchesRegularExpression('/pushed [1-9]\d* object\(s\)/', $push['stdout'], $push['stdout']);
            self::assertSame([], glob($shared . '/graph/*/*.json') ?: [], 'no graph was published');

            $fixture->repo->git('checkout', '--', 'src/Money.php');
            $clean = $fixture->replay(['push', '--graph'], $env);
            self::assertSame(0, $clean['exitCode'], $clean['stdout'] . $clean['stderr']);
            self::assertNotSame([], glob($shared . '/graph/*/*.json') ?: [], 'a clean tree publishes its graph');
        } finally {
            TempDir::remove($shared);
        }
    }

    private function recordedPlain(): FixtureProject
    {
        $this->fixture = FixtureProject::plain();

        $recorded = $this->fixture->replay(['record'], self::env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        return $this->fixture;
    }

    private function breakMoneyAdd(FixtureProject $fixture): void
    {
        $fixture->write('src/Money.php', str_replace(self::GOOD_ADD, self::BAD_ADD, $fixture->read('src/Money.php')));
    }

    private function fixMoneyAdd(FixtureProject $fixture): void
    {
        $fixture->write('src/Money.php', str_replace(self::BAD_ADD, self::GOOD_ADD, $fixture->read('src/Money.php')));
    }

    /** What a graph written before this release holds: every result without its digest. */
    private static function stripDigests(FixtureProject $fixture): void
    {
        $path = ReplayAssert::graphPath($fixture);
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);

        foreach ($data['baselines'] as $branch => $baseline) {
            foreach ($baseline['results'] as $testId => $result) {
                unset($data['baselines'][$branch]['results'][$testId]['n']);
            }
        }

        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    private static function greetingFixtureTest(): string
    {
        return <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Tests;

            use PHPUnit\Framework\TestCase;

            final class GreetingFixtureTest extends TestCase
            {
                public function testTheFixtureGreets(): void
                {
                    self::assertSame("Hello\n", file_get_contents(__DIR__ . '/Fixtures/greeting.txt'));
                }
            }

            PHP;
    }

    /** @return array<string, string> */
    private static function env(): array
    {
        return ['CI' => ''];
    }
}
