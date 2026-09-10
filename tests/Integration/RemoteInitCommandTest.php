<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Console\Commands\RemoteInitCommand;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `phpunit-replay remote:init` (docs/sharing-the-cache.md "Setup: dedicated git repository",
 * DECISIONS.md D-038/D-045): derives the cache repository from `origin`, proves a round trip
 * through it, and writes the config without ever editing one that exists.
 *
 * Nothing here touches the network or a real forge, and nothing here can invoke `gh`: the
 * "origin" and "cache" repositories are local bare repositories reached through `file://`
 * (which `GitRemoteCache` accepts for exactly this reason), and every case either passes
 * `--no-create` / `--same-repo` (both of which skip creation outright) or `--dry-run`, which
 * contacts nothing at all.
 *
 * The state directory comes from `PHPUNIT_REPLAY_STATE_DIR` in `$_SERVER` rather than from a
 * `phpunit-replay.php`, because the file's *absence* is what half of these cases are about —
 * `Config::mergeEnv()` reads `$_SERVER` directly, and the command runs in-process through
 * `CommandTester`, so there is no subprocess to export anything to.
 */
final class RemoteInitCommandTest extends TestCase
{
    private string $base;

    private string $projectRoot;

    private string $stateDir;

    private string $originRepo;

    private string $cacheRepo;

    private GitRepo $repo;

    private ?string $previousCwd;

    private ?string $previousStateDir;

    protected function setUp(): void
    {
        $this->base = TempDir::make('remote-init');

        // Deliberately NOT named after the repository: the proposed cache name must come from
        // origin's repository name, so a directory-name-based implementation fails these tests.
        $this->projectRoot = $this->base . '/checkout-named-differently';
        $this->stateDir = $this->base . '/state';
        $this->originRepo = $this->base . '/forge/acme/widget.git';
        $this->cacheRepo = $this->base . '/forge/acme/widget-replay-cache.git';

        $this->bare($this->originRepo);
        $this->bare($this->cacheRepo);

        $this->repo = GitRepo::init($this->projectRoot);
        $this->repo->write('src/Widget.php', "<?php\n\nfinal class Widget\n{\n}\n");
        $this->repo->commitAll('initial');
        $this->repo->git('remote', 'add', 'origin', 'file://' . $this->originRepo);
        $this->repo->git('push', '-q', 'origin', 'main');

        $this->previousCwd = getcwd() ?: null;
        $this->previousStateDir = isset($_SERVER['PHPUNIT_REPLAY_STATE_DIR']) && is_string($_SERVER['PHPUNIT_REPLAY_STATE_DIR'])
            ? $_SERVER['PHPUNIT_REPLAY_STATE_DIR']
            : null;
        $_SERVER['PHPUNIT_REPLAY_STATE_DIR'] = $this->stateDir;

        chdir($this->projectRoot);
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== null) {
            chdir($this->previousCwd);
        }

        if ($this->previousStateDir === null) {
            unset($_SERVER['PHPUNIT_REPLAY_STATE_DIR']);
        } else {
            $_SERVER['PHPUNIT_REPLAY_STATE_DIR'] = $this->previousStateDir;
        }

        TempDir::remove($this->base);
    }

    public function test_the_proposed_cache_repository_comes_from_origins_repository_name_not_the_directory_name(): void
    {
        $tester = $this->init(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('file://' . $this->cacheRepo, $tester->getDisplay());
        self::assertStringNotContainsString('checkout-named-differently-replay-cache', $tester->getDisplay());
        self::assertStringContainsString('repository widget', $tester->getDisplay());
    }

    public function test_a_missing_origin_explains_why_origin_is_required_and_exits_non_zero(): void
    {
        $this->repo->git('remote', 'remove', 'origin');

        $tester = $this->init(['--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('no `origin` remote', $tester->getDisplay());
        self::assertStringContainsString('ProjectKey::shared()', $tester->getDisplay());
    }

    public function test_dry_run_writes_no_config_no_probe_and_no_commit(): void
    {
        $tester = $this->init(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('would write', $tester->getDisplay());
        self::assertStringContainsString('dry run: nothing was contacted, created, pushed or written.', $tester->getDisplay());

        self::assertFileDoesNotExist($this->projectRoot . '/phpunit-replay.php');
        self::assertSame('', trim($this->git($this->cacheRepo, ['ls-remote', '--heads', $this->cacheRepo])));
        self::assertDirectoryDoesNotExist($this->stateDir . '/remote-init');
    }

    public function test_the_probe_round_trip_succeeds_against_a_local_bare_repository(): void
    {
        $tester = $this->init(['--no-create' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('already there', $tester->getDisplay());
        self::assertStringContainsString('the round trip works', $tester->getDisplay());
        self::assertStringContainsString('probe deleted', $tester->getDisplay());
        self::assertFileExists($this->projectRoot . '/phpunit-replay.php');
    }

    public function test_the_probe_is_pushed_and_then_removed_from_the_cache_repository(): void
    {
        $tester = $this->init(['--no-create' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        // The push really happened (the branch exists upstream)...
        self::assertNotSame('', trim($this->git($this->cacheRepo, ['ls-remote', '--heads', $this->cacheRepo, 'main'])));

        // ...and the probe object is not in it any more: the only file ever written was the
        // probe, so the branch tip's tree is empty.
        self::assertSame('', trim($this->git($this->cacheRepo, ['ls-tree', '-r', '--name-only', 'main'])));

        // The scratch mirrors the probe cloned into are gone too.
        self::assertDirectoryDoesNotExist($this->stateDir . '/remote-init');
    }

    public function test_a_probe_that_cannot_reach_the_cache_repository_reports_the_backends_own_error(): void
    {
        $this->repo->git('remote', 'set-url', 'origin', 'file://' . $this->base . '/forge/acme/absent.git');

        $tester = $this->init(['--no-create' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('the round trip does NOT work', $tester->getDisplay());
        self::assertMatchesRegularExpression('/git (clone|fetch|push)/', $tester->getDisplay());
    }

    public function test_an_existing_config_file_is_left_byte_identical_and_the_lines_are_printed_instead(): void
    {
        $existing = "<?php\n\nreturn [\n    'default_branch' => 'develop',\n    'baseline_branches' => ['develop', 'main'],\n];\n";
        TempDir::write($this->projectRoot . '/phpunit-replay.php', $existing);

        $tester = $this->init(['--no-create' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame($existing, file_get_contents($this->projectRoot . '/phpunit-replay.php'));
        self::assertStringContainsString('already exists and was left untouched', $tester->getDisplay());
        self::assertStringContainsString('add these lines to the array it returns:', $tester->getDisplay());
        self::assertStringContainsString("'remote' => 'file://" . $this->cacheRepo . "',", $tester->getDisplay());
        self::assertStringContainsString("'remote_branch' => 'main',", $tester->getDisplay());
        self::assertStringContainsString("'remote_push' => 'off',", $tester->getDisplay());
    }

    public function test_same_repo_configures_origins_own_url_on_an_orphan_branch_and_prints_the_clone_cost(): void
    {
        $tester = $this->init(['--same-repo' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('nothing to create and no access to grant', $tester->getDisplay());
        self::assertStringContainsString('a plain `git clone` of this project fetches every branch', $tester->getDisplay());
        self::assertStringContainsString('every checkout', $tester->getDisplay());

        $written = (string) file_get_contents($this->projectRoot . '/phpunit-replay.php');
        self::assertStringContainsString("'remote' => 'file://" . $this->originRepo . "',", $written);
        self::assertStringContainsString("'remote_branch' => 'phpunit-replay-cache',", $written);

        // D-045's shape, verified rather than assumed: the cache branch exists in the project's
        // own repository, shares no history with `main`, and carries none of its files (the
        // probe having been cleaned up, its tree is empty while main still has the source).
        self::assertNotSame('', trim($this->git($this->originRepo, ['ls-remote', '--heads', $this->originRepo, 'phpunit-replay-cache'])));
        self::assertSame('', trim($this->git($this->originRepo, ['ls-tree', '-r', '--name-only', 'phpunit-replay-cache'])));
        self::assertStringContainsString('src/Widget.php', $this->git($this->originRepo, ['ls-tree', '-r', '--name-only', 'main']));
    }

    /**
     * `--same-repo --branch=main` would have the probe commit onto the project's own code
     * branch, which is a write to real infrastructure nobody asked for — refused before
     * anything runs, rather than warned about afterwards.
     */
    public function test_same_repo_refuses_a_branch_that_holds_code(): void
    {
        $tester = $this->init(['--same-repo' => true, '--branch' => 'main']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('refused: --same-repo would publish the cache to `main`', $tester->getDisplay());
        self::assertStringContainsString('phpunit-replay-cache', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectRoot . '/phpunit-replay.php');
        self::assertSame('', trim($this->git($this->originRepo, ['ls-remote', '--heads', $this->originRepo, 'phpunit-replay-cache'])));
    }

    public function test_public_is_refused_in_one_line(): void
    {
        $tester = $this->init(['--public' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('there is no --public', $tester->getDisplay());
        self::assertStringContainsString('source-adjacent', $tester->getDisplay());
        self::assertFileDoesNotExist($this->projectRoot . '/phpunit-replay.php');
    }

    /** @param array<string, bool|string> $input */
    private function init(array $input): CommandTester
    {
        $tester = new CommandTester(new RemoteInitCommand());
        $tester->execute($input);

        return $tester;
    }

    private function bare(string $path): void
    {
        if (! @mkdir($path, 0o775, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create ' . $path);
        }

        $this->git($path, ['init', '--bare', '-q', '-b', 'main']);
    }

    /** @param list<string> $arguments */
    private function git(string $dir, array $arguments): string
    {
        $process = new Process(['git', ...$arguments], $dir);
        $process->setTimeout(30.0);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'git %s failed (%d): %s',
                implode(' ', $arguments),
                $process->getExitCode() ?? -1,
                $process->getErrorOutput(),
            ));
        }

        return $process->getOutput();
    }
}
