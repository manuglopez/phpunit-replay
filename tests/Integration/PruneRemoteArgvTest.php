<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use DateTimeImmutable;
use Manuglopez\Replay\Cache\Remote\FilesystemRemoteCache;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay prune --remote --keep-months=N [--squash]` invoked through the REAL
 * `bin/phpunit-replay` binary (unlike {@see PruneRemoteCommandTest}, which drives
 * `PruneCommand` directly via `CommandTester` because — before this test's fix —
 * `Console\Application`'s argv pre-splitter did not know `prune`'s `--remote`,
 * `--keep-months` and `--squash` long options and would have misrouted them to the
 * PHPUnit passthrough bucket, where `PruneCommand` (no `phpunit-args` argument) would
 * have failed with a "too many arguments" error instead of running).
 *
 * The remote here is a `file://` directory (a {@see FilesystemRemoteCache}), which
 * doubles as the "non-git backend" for the `--squash` case: `--squash` is a git-only
 * feature and must be rejected gracefully (exit 0, a warning line) rather than crash a
 * filesystem-backed remote.
 */
final class PruneRemoteArgvTest extends TestCase
{
    private FixtureProject $fixture;

    private string $sharedCache;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $this->sharedCache = TempDir::make('prune-remote-argv');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
        TempDir::remove($this->sharedCache);
    }

    public function test_keep_months_reaches_the_command_and_prunes_unreferenced_old_shards(): void
    {
        $currentMonth = (new DateTimeImmutable('now'))->format('Y-m');

        $cache = new FilesystemRemoteCache($this->sharedCache);
        $cache->begin();
        $cache->put('objects/2020-01/unreferenced.json', '{"i":1}');
        $cache->put('objects/2020-02/referenced.json', '{"i":2}');
        $cache->put('objects/' . $currentMonth . '/current.json', '{"i":3}');
        $cache->put('graph/project/main.json', (string) json_encode([
            'schema' => 1,
            'baselines' => [
                'main' => [
                    'results' => [
                        'Tests\FooTest::it_works' => ['k' => 'referenced'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $cache->end();
        self::assertNull($cache->lastError());

        $pruned = $this->fixture->replay(['prune', '--remote', '--keep-months=3'], $this->env());

        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertStringContainsString('removed 1 object(s), kept 1 referenced object(s)', $pruned['stdout']);

        self::assertFileDoesNotExist($this->sharedCache . '/objects/2020-01/unreferenced.json');
        self::assertFileExists($this->sharedCache . '/objects/2020-02/referenced.json');
        self::assertFileExists($this->sharedCache . '/objects/' . $currentMonth . '/current.json');
    }

    public function test_squash_is_rejected_gracefully_on_a_non_git_backend(): void
    {
        $cache = new FilesystemRemoteCache($this->sharedCache);
        $cache->begin();
        $cache->put('objects/' . (new DateTimeImmutable('now'))->format('Y-m') . '/current.json', '{"i":1}');
        $cache->end();
        self::assertNull($cache->lastError());

        $pruned = $this->fixture->replay(['prune', '--remote', '--keep-months=3', '--squash'], $this->env());

        self::assertSame(0, $pruned['exitCode'], $pruned['stdout'] . $pruned['stderr']);
        self::assertStringContainsString('removed 0 object(s), kept 0 referenced object(s)', $pruned['stdout']);
        self::assertStringContainsString('--squash is only supported by the git backend', $pruned['stdout']);

        // The filesystem remote must survive untouched: no crash, no partial rewrite.
        self::assertFileExists($this->sharedCache . '/objects/' . (new DateTimeImmutable('now'))->format('Y-m') . '/current.json');
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $this->sharedCache];
    }
}
