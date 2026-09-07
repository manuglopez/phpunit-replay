<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use DateTimeImmutable;
use Manuglopez\Replay\Cache\Remote\GitRemoteCache;
use Manuglopez\Replay\Console\Commands\PruneCommand;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

/**
 * `phpunit-replay prune --remote [--keep-months=N] [--squash]` (docs/INTERNALS.md
 * "GitRemoteCache — automatic maintenance", SPEC.md §9): garbage-collects a git-backed
 * remote cache instead of the local graph.
 *
 * `PruneCommand` is exercised directly through Symfony's `CommandTester` (not through the
 * `bin/phpunit-replay` binary): `Console\Application`'s own argv splitter hardcodes the long
 * options it recognises per command and is out of scope for this change, so a real CLI
 * invocation of `prune --remote --keep-months=3` would have its options misrouted to the
 * PHPUnit passthrough bucket. `PruneCommand::execute()` itself only depends on `getcwd()`
 * and the project's `phpunit-replay.php`, both of which this test controls directly.
 */
final class PruneRemoteCommandTest extends TestCase
{
    private string $base;

    private string $projectRoot;

    private string $stateDir;

    private string $bareRepo;

    private ?string $previousCwd;

    protected function setUp(): void
    {
        $this->base = TempDir::make('prune-remote');
        $this->projectRoot = $this->base . '/project';
        $this->stateDir = $this->base . '/state';
        $this->bareRepo = $this->base . '/cache.git';

        if (! @mkdir($this->bareRepo, 0o775, true) && ! is_dir($this->bareRepo)) {
            throw new RuntimeException('Cannot create ' . $this->bareRepo);
        }

        $this->git($this->bareRepo, ['init', '--bare', '-q', '-b', 'main']);

        TempDir::write($this->projectRoot . '/.gitkeep', '');
        $this->git($this->projectRoot, ['init', '-q', '-b', 'main']);
        $this->git($this->projectRoot, ['config', 'user.email', 'tests@example.invalid']);
        $this->git($this->projectRoot, ['config', 'user.name', 'Replay Tests']);
        $this->git($this->projectRoot, ['add', '-A']);
        $this->git($this->projectRoot, ['commit', '-q', '-m', 'initial']);

        TempDir::write($this->projectRoot . '/phpunit-replay.php', sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'state_dir' => %s,\n    'remote' => %s,\n];\n",
            var_export($this->stateDir, true),
            var_export('file://' . $this->bareRepo, true),
        ));

        $this->previousCwd = getcwd() ?: null;
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== null) {
            chdir($this->previousCwd);
        }

        TempDir::remove($this->base);
    }

    public function test_keep_months_removes_old_unreferenced_shards_but_spares_referenced_and_recent_ones(): void
    {
        $seed = new GitRemoteCache('file://' . $this->bareRepo, $this->base . '/seed-mirror', 'main', 0, 30);
        $seed->begin();

        // Four old shards, one of them referenced from a graph/** file, plus one current shard.
        $seed->put('objects/2024-01/unreferenced-a.json', '{"i":1}');
        $seed->put('objects/2024-06/referenced.json', '{"i":2}');
        $seed->put('objects/2024-12/unreferenced-b.json', '{"i":3}');
        $seed->put('objects/2025-06/unreferenced-c.json', '{"i":4}');

        $currentMonth = (new DateTimeImmutable('now'))->format('Y-m');
        $seed->put('objects/' . $currentMonth . '/current.json', '{"i":5}');

        $seed->put('graph/project/main.json', json_encode([
            'schema' => 1,
            'baselines' => [
                'main' => [
                    'results' => [
                        'Tests\FooTest::it_works' => ['k' => 'referenced'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $seed->end();
        self::assertNull($seed->lastError());

        chdir($this->projectRoot);
        $tester = new CommandTester(new PruneCommand());
        $tester->execute(['--remote' => true, '--keep-months' => 3]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('removed 3 object(s), kept 1 referenced object(s)', $tester->getDisplay());

        $after = new GitRemoteCache('file://' . $this->bareRepo, $this->base . '/verify-mirror', 'main', 0, 30);
        $after->begin();

        $remaining = $after->keys('objects/');
        sort($remaining);

        self::assertSame(
            ['objects/2024-06/referenced.json', 'objects/' . $currentMonth . '/current.json'],
            $remaining,
        );
    }

    public function test_squash_rewrites_the_remote_branch_as_a_single_commit(): void
    {
        // Current month: none of these are eligible for the --keep-months=3 deletion pass
        // that runs before --squash, so the squash test exercises only the history rewrite.
        $currentMonth = (new DateTimeImmutable('now'))->format('Y-m');

        $seed = new GitRemoteCache('file://' . $this->bareRepo, $this->base . '/seed-mirror', 'main', 0, 30);
        $seed->begin();
        $seed->put('objects/' . $currentMonth . '/a.json', '{"i":1}');
        $seed->end();
        $seed->put('objects/' . $currentMonth . '/b.json', '{"i":2}');
        $seed->end();
        $seed->put('objects/' . $currentMonth . '/c.json', '{"i":3}');
        $seed->end();
        self::assertNull($seed->lastError());

        $before = (int) trim($this->git($this->bareRepo, ['rev-list', '--count', 'main']));
        self::assertGreaterThan(1, $before, 'expected more than one commit before squashing');

        chdir($this->projectRoot);
        $tester = new CommandTester(new PruneCommand());
        $tester->execute(['--remote' => true, '--keep-months' => 3, '--squash' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('squashed remote history', $tester->getDisplay());

        $after = (int) trim($this->git($this->bareRepo, ['rev-list', '--count', 'main']));
        self::assertSame(1, $after);

        $verify = new GitRemoteCache('file://' . $this->bareRepo, $this->base . '/verify-mirror', 'main', 0, 30);
        $verify->begin();
        self::assertSame('{"i":1}', $verify->get('objects/' . $currentMonth . '/a.json'));
        self::assertSame('{"i":2}', $verify->get('objects/' . $currentMonth . '/b.json'));
        self::assertSame('{"i":3}', $verify->get('objects/' . $currentMonth . '/c.json'));
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
