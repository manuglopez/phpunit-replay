<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache\Remote;

use Manuglopez\Replay\Cache\Remote\GitRemoteCache;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * `GitRemoteCache` (docs/INTERNALS.md "GitRemoteCache — automatic maintenance", SPEC.md §9,
 * DECISIONS.md D-038): a shallow single-branch mirror of a dedicated git repository used as
 * a content-addressed remote cache. Every scenario here uses a bare repository in a TempDir
 * as the "origin" — no real network involved.
 */
final class GitRemoteCacheTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $dir) {
            TempDir::remove($dir);
        }

        $this->cleanup = [];
    }

    public function test_two_clients_with_different_mirrors_both_end_up_upstream(): void
    {
        // Origin starts completely empty: no commits, no "main" branch at all yet — this
        // exercises the "branch missing upstream" bootstrap (git init + orphan commit).
        $bare = $this->bareRepo();

        $clientA = $this->cacheFor($bare);
        $clientA->begin();
        self::assertNull($clientA->lastError());
        self::assertTrue($clientA->put('objects/2026-09/aaa.json', '{"a":1}'));
        $clientA->end();
        self::assertNull($clientA->lastError());

        $clientB = $this->cacheFor($bare);
        $clientB->begin();
        self::assertNull($clientB->lastError());
        self::assertTrue($clientB->put('objects/2026-09/bbb.json', '{"b":2}'));
        $clientB->end();
        self::assertNull($clientB->lastError());

        self::assertSame('{"a":1}', $this->showUpstream($bare, 'objects/2026-09/aaa.json'));
        self::assertSame('{"b":2}', $this->showUpstream($bare, 'objects/2026-09/bbb.json'));
    }

    public function test_a_push_rejected_after_another_client_already_pushed_rebases_and_retries(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'seed.txt', 'seed');

        $clientC = $this->cacheFor($bare);
        $clientC->begin();
        self::assertTrue($clientC->put('objects/2026-09/c.json', '{"c":1}'));
        // clientC does not end() yet: its local commit will not exist upstream when D pushes.

        $clientD = $this->cacheFor($bare);
        $clientD->begin();
        self::assertTrue($clientD->put('objects/2026-09/d.json', '{"d":1}'));
        $clientD->end();
        self::assertNull($clientD->lastError());

        // clientC's push is now rejected (non-fast-forward): fetch + rebase FETCH_HEAD, retry.
        $clientC->end();
        self::assertNull($clientC->lastError());

        self::assertSame('{"c":1}', $this->showUpstream($bare, 'objects/2026-09/c.json'));
        self::assertSame('{"d":1}', $this->showUpstream($bare, 'objects/2026-09/d.json'));
    }

    public function test_begin_with_zero_refresh_seconds_always_refreshes_and_sees_the_other_clients_object(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'seed.txt', 'seed');

        $reader = $this->cacheFor($bare);
        $reader->begin();
        self::assertNull($reader->get('objects/2026-09/shared.json'));

        $writer = $this->cacheFor($bare);
        $writer->begin();
        $writer->put('objects/2026-09/shared.json', '{"shared":true}');
        $writer->end();
        self::assertNull($writer->lastError());

        // refreshSeconds = 0 (cacheFor()) means begin() always fetches + resets.
        $reader->begin();
        self::assertSame('{"shared":true}', $reader->get('objects/2026-09/shared.json'));
    }

    public function test_offline_remote_never_throws_records_last_error_but_still_works_locally(): void
    {
        $stateDir = $this->tempState();
        $badUrl = 'file:///this/path/does/not/exist-' . bin2hex(random_bytes(4)) . '.git';

        $cache = new GitRemoteCache($badUrl, $stateDir . '/remote/git', 'main', 0, 5);

        $cache->begin();
        self::assertNotNull($cache->lastError());

        self::assertTrue($cache->put('objects/2026-09/offline.json', '{"offline":true}'));
        self::assertSame('{"offline":true}', $cache->get('objects/2026-09/offline.json'));

        $cache->end();
        self::assertNotNull($cache->lastError());
    }

    public function test_stale_mirror_picks_up_a_rewritten_upstream_history(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'objects/A.json', 'A');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertSame('A', $client->get('objects/A.json'));

        $this->rewriteUpstream($bare, 'objects/B.json', 'B');

        // refreshSeconds = 0: the next begin() must notice the marker is stale and refresh.
        $client->begin();
        self::assertNull($client->lastError());
        self::assertNull($client->get('objects/A.json'));
        self::assertSame('B', $client->get('objects/B.json'));
    }

    public function test_keys_lists_a_prefix_and_delete_removes_a_key(): void
    {
        $bare = $this->bareRepo();

        $cache = $this->cacheFor($bare);
        $cache->begin();
        $cache->put('objects/2026-01/old.json', '{"k":1}');
        $cache->put('objects/2026-07/new.json', '{"k":2}');
        $cache->put('graph/project/main.json', '{"baselines":{}}');
        $cache->end();
        self::assertNull($cache->lastError());

        self::assertSame(
            ['objects/2026-01/old.json', 'objects/2026-07/new.json'],
            $cache->keys('objects/'),
        );
        self::assertSame(['graph/project/main.json'], $cache->keys('graph/'));

        self::assertTrue($cache->has('objects/2026-01/old.json'));
        self::assertTrue($cache->delete('objects/2026-01/old.json'));
        self::assertFalse($cache->has('objects/2026-01/old.json'));
        self::assertSame(['objects/2026-07/new.json'], $cache->keys('objects/'));

        // Deleting an already-absent key is idempotent, never an error.
        self::assertTrue($cache->delete('objects/2026-01/old.json'));
    }

    public function test_from_config_resolves_every_documented_url_form(): void
    {
        $stateDir = $this->tempState();

        $table = [
            'git+ssh://git@host/path.git' => 'ssh://git@host/path.git',
            'git+https://host/path.git' => 'https://host/path.git',
            'ssh://git@host/path.git' => 'ssh://git@host/path.git',
            'git@host:path.git' => 'git@host:path.git',
            'https://host/path.git' => 'https://host/path.git',
            '/local/bare/repo.git' => '/local/bare/repo.git',
            'file:///local/bare/repo.git' => 'file:///local/bare/repo.git',
        ];

        foreach ($table as $configured => $expectedUrl) {
            $config = Config::fromArray(['remote' => $configured]);
            $cache = GitRemoteCache::fromConfig($config, $stateDir);

            self::assertSame($expectedUrl, $cache->remoteUrl(), $configured);
            self::assertSame('main', $cache->branch(), $configured);
            self::assertSame($stateDir . '/remote/git', $cache->mirrorDir(), $configured);
            self::assertSame('git', $cache->name());
        }
    }

    private function cacheFor(string $bareRepo): GitRemoteCache
    {
        $stateDir = $this->tempState();

        return new GitRemoteCache('file://' . $bareRepo, $stateDir . '/remote/git', 'main', 0, 30);
    }

    private function tempState(): string
    {
        $dir = TempDir::make('gitremote-state');
        $this->cleanup[] = $dir;

        return $dir;
    }

    /** Creates an empty bare repository (no commits, no branches) to act as the "origin". */
    private function bareRepo(): string
    {
        $dir = TempDir::make('gitremote-bare') . '.git';
        $this->cleanup[] = $dir;

        if (! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create ' . $dir);
        }

        $this->git($dir, ['init', '--bare', '-q', '-b', 'main']);

        return $dir;
    }

    /** Pushes one file to the bare repo's "main" branch through a throw-away seed working copy. */
    private function seedUpstream(string $bareRepo, string $relative, string $content): void
    {
        $seed = TempDir::make('gitremote-seed');
        $this->cleanup[] = $seed;

        $this->git($seed, ['init', '-q', '-b', 'main']);
        $this->git($seed, ['config', 'user.email', 'tests@example.invalid']);
        $this->git($seed, ['config', 'user.name', 'Replay Tests']);

        TempDir::write($seed . '/' . $relative, $content);

        $this->git($seed, ['add', '-A']);
        $this->git($seed, ['commit', '-q', '-m', 'seed']);
        $this->git($seed, ['remote', 'add', 'origin', $bareRepo]);
        $this->git($seed, ['push', '-q', 'origin', 'main']);
    }

    /** Force-rewrites the bare repo's "main" branch as a single orphan commit with only $relative in it. */
    private function rewriteUpstream(string $bareRepo, string $relative, string $content): void
    {
        $seed = TempDir::make('gitremote-rewrite');
        $this->cleanup[] = $seed;

        $this->git($seed, ['clone', '-q', $bareRepo, '.']);
        $this->git($seed, ['config', 'user.email', 'tests@example.invalid']);
        $this->git($seed, ['config', 'user.name', 'Replay Tests']);
        $this->git($seed, ['checkout', '-q', '--orphan', 'rewrite-tmp']);
        $this->git($seed, ['rm', '-rf', '-q', '.']);

        TempDir::write($seed . '/' . $relative, $content);

        $this->git($seed, ['add', '-A']);
        $this->git($seed, ['commit', '-q', '-m', 'rewritten']);
        $this->git($seed, ['branch', '-M', 'main']);
        $this->git($seed, ['push', '-q', '--force', 'origin', 'main']);
    }

    private function showUpstream(string $bareRepo, string $path): ?string
    {
        $process = new Process(['git', 'show', 'main:' . $path], $bareRepo);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
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
