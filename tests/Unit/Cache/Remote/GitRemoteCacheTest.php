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

    /**
     * The actual race the fix needs to survive (docs/INTERNALS.md "GitRemoteCache —
     * automatic maintenance"): both clients `begin()` against a genuinely EMPTY bare repo —
     * no branch upstream for either to see — so BOTH independently bootstrap their own
     * orphan `replay: init` root (the "branch missing upstream" path). Whichever `end()`s
     * second gets its push rejected against the other's now-published, unrelated history.
     *
     * Deterministic by construction (both `begin()` before either `end()`s, then C ends
     * first, then D): never rebase, so the reconciliation is immune to git's shallow-fetch
     * "unrelated histories" flakiness that made this test fail on CI while passing locally.
     */
    public function test_a_push_rejected_after_another_client_already_pushed_resets_and_retries(): void
    {
        $bare = $this->bareRepo();

        $clientC = $this->cacheFor($bare);
        $clientC->begin();
        self::assertNull($clientC->lastError());
        self::assertTrue($clientC->put('objects/2026-09/c.json', '{"c":1}'));

        $clientD = $this->cacheFor($bare);
        $clientD->begin();
        self::assertNull($clientD->lastError());
        self::assertTrue($clientD->put('objects/2026-09/d.json', '{"d":1}'));

        // C pushes first: its own orphan history becomes the branch.
        $clientC->end();
        self::assertNull($clientC->lastError());

        // D's push is rejected (its own, unrelated orphan root vs. C's now-published one).
        // Never rebase: adopt upstream wholesale and re-write D's own buffered object on top.
        $clientD->end();
        self::assertNull($clientD->lastError());

        self::assertSame('{"c":1}', $this->showUpstream($bare, 'objects/2026-09/c.json'));
        self::assertSame('{"d":1}', $this->showUpstream($bare, 'objects/2026-09/d.json'));

        // One linear history: no merge commit (reconciliation only ever resets + re-commits)
        // and exactly one root commit (D's own orphan history must never reach upstream).
        self::assertSame('', trim($this->git($bare, ['log', '--merges', '--format=%H', 'main'])));
        self::assertCount(1, self::nonEmptyLines($this->git($bare, ['log', '--max-parents=0', '--format=%H', 'main'])));
    }

    /**
     * A GC job (`prune --remote --squash`) can rewrite the whole branch as a fresh orphan
     * commit between one client's `begin()` and `end()` (docs/INTERNALS.md "GitRemoteCache
     * — automatic maintenance"): the client's own commit, parented on the pre-squash
     * history, is rejected outright as non-fast-forward against a totally different tree.
     * Reconciliation must adopt the squashed history wholesale and still not lose the
     * client's own write.
     */
    public function test_upstream_squashed_between_begin_and_end_is_adopted_without_losing_the_local_write(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'seed.txt', 'seed');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertNull($client->lastError());
        self::assertTrue($client->put('objects/2026-09/e.json', '{"e":1}'));

        // Simulates the squash: the branch is rewritten as an unrelated orphan history
        // while the client sits on its own (now stale) clone of the pre-squash "seed" tip.
        $this->rewriteUpstream($bare, 'objects/A.json', 'A');

        $client->end();
        self::assertNull($client->lastError());

        self::assertSame('{"e":1}', $this->showUpstream($bare, 'objects/2026-09/e.json'));
        self::assertSame('A', $this->showUpstream($bare, 'objects/A.json'));
    }

    /**
     * The bug this fix closes: {@see GitRemoteCache::delete()} only unlinked the mirror's
     * working-tree file and recorded the deletion nowhere else, so
     * {@see GitRemoteCache}'s reconciliation (which only ever knew how to replay THIS run's
     * buffered {@see GitRemoteCache::put()} calls) had no way to redo a deletion after a
     * rejected push's `git reset --hard FETCH_HEAD` restored the file exactly as upstream
     * still had it. `end()` must not report success while quietly leaving the object upstream.
     */
    public function test_a_deletion_survives_a_rejected_push_and_is_gone_upstream(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'objects/2026-09/c.json', '{"c":1}');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertTrue($client->has('objects/2026-09/c.json'));
        self::assertTrue($client->delete('objects/2026-09/c.json'));

        // An unrelated concurrent write moves upstream on WITHOUT touching c.json, so the
        // client's own push is rejected (its local history and the new upstream tip are
        // sibling commits of the seeded one) and reconciliation must reset onto a tree that
        // still has c.json — exactly the moment the deletion can be lost.
        $this->extendUpstream($bare, 'objects/2026-09/other.json', '{"other":1}');

        $client->end();
        self::assertNull($client->lastError());

        self::assertNull($this->showUpstream($bare, 'objects/2026-09/c.json'));
        self::assertSame('{"other":1}', $this->showUpstream($bare, 'objects/2026-09/other.json'));
    }

    /**
     * The narrower shape of the same root cause: {@see GitRemoteCache}'s write buffer had no
     * idea a key it held was deleted again on this very instance, so replaying it after a
     * reset would resurrect an object the caller explicitly removed before `end()` ever ran.
     */
    public function test_put_then_delete_on_one_instance_does_not_resurrect_the_object(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'seed.txt', 'seed');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertTrue($client->put('objects/2026-09/new.json', '{"new":1}'));
        self::assertTrue($client->delete('objects/2026-09/new.json'));

        $this->extendUpstream($bare, 'objects/2026-09/other.json', '{"other":1}');

        $client->end();
        self::assertNull($client->lastError());

        self::assertNull($this->showUpstream($bare, 'objects/2026-09/new.json'));
        self::assertSame('{"other":1}', $this->showUpstream($bare, 'objects/2026-09/other.json'));
    }

    /**
     * The mirror image of the previous test: a `put()` after a `delete()` for the same key on
     * the same instance must cancel the tombstone rather than the other way around — the key
     * is being (re)written, not removed, and the newer body must be what lands upstream.
     */
    public function test_delete_then_put_on_one_instance_publishes_the_new_body(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'objects/2026-09/c.json', '{"c":1}');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertTrue($client->delete('objects/2026-09/c.json'));
        self::assertTrue($client->put('objects/2026-09/c.json', '{"c":2}'));

        $this->extendUpstream($bare, 'objects/2026-09/other.json', '{"other":1}');

        $client->end();
        self::assertNull($client->lastError());

        self::assertSame('{"c":2}', $this->showUpstream($bare, 'objects/2026-09/c.json'));
        self::assertSame('{"other":1}', $this->showUpstream($bare, 'objects/2026-09/other.json'));
    }

    public function test_a_rejected_push_with_both_a_write_and_an_unrelated_deletion_lands_both(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'objects/2026-09/old.json', '{"old":1}');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertTrue($client->put('objects/2026-09/new.json', '{"new":1}'));
        self::assertTrue($client->delete('objects/2026-09/old.json'));

        $this->extendUpstream($bare, 'objects/2026-09/other.json', '{"other":1}');

        $client->end();
        self::assertNull($client->lastError());

        self::assertSame('{"new":1}', $this->showUpstream($bare, 'objects/2026-09/new.json'));
        self::assertNull($this->showUpstream($bare, 'objects/2026-09/old.json'));
        self::assertSame('{"other":1}', $this->showUpstream($bare, 'objects/2026-09/other.json'));
    }

    /**
     * Regression guard: a rejected push carrying only writes (no deletions at all) must
     * behave exactly as before this fix — no tombstones are recorded, so reconciliation only
     * ever replays the buffer, same as always.
     */
    public function test_a_plain_rejected_push_with_only_writes_is_unchanged(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'seed.txt', 'seed');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertTrue($client->put('objects/2026-09/mine.json', '{"mine":1}'));

        $this->extendUpstream($bare, 'objects/2026-09/other.json', '{"other":1}');

        $client->end();
        self::assertNull($client->lastError());

        self::assertSame('{"mine":1}', $this->showUpstream($bare, 'objects/2026-09/mine.json'));
        self::assertSame('{"other":1}', $this->showUpstream($bare, 'objects/2026-09/other.json'));
    }

    /**
     * The deliberate edge case behind the fix: a key can exist upstream without ever being
     * fetched into THIS shallow mirror, because a mirror is only ever as fresh as its last
     * `begin()` (up to `remoteRefreshSeconds` stale by design, docs/INTERNALS.md
     * "GitRemoteCache — automatic maintenance"). `delete()` tombstones the key even though
     * `is_file()` was false at call time, so reconciliation still removes it if a rejected
     * push's reset lands on a newer upstream tip that turns out to have had it all along.
     */
    public function test_delete_of_a_key_never_fetched_into_a_stale_mirror_still_tombstones_it(): void
    {
        $bare = $this->bareRepo();
        $this->seedUpstream($bare, 'seed.txt', 'seed');

        $client = $this->cacheFor($bare);
        $client->begin();
        self::assertFalse($client->has('objects/2026-09/ghost.json'));
        self::assertTrue($client->delete('objects/2026-09/ghost.json'));
        self::assertTrue($client->put('objects/2026-09/mine.json', '{"mine":1}'));

        // Simulates another client publishing ghost.json after THIS client's begin(): it is
        // upstream by the time the retry fetches, but was never pulled into this mirror.
        $this->extendUpstream($bare, 'objects/2026-09/ghost.json', '{"ghost":1}');

        $client->end();
        self::assertNull($client->lastError());

        self::assertNull($this->showUpstream($bare, 'objects/2026-09/ghost.json'));
        self::assertSame('{"mine":1}', $this->showUpstream($bare, 'objects/2026-09/mine.json'));
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

    /**
     * Pushes one more commit onto the bare repo's existing "main" tip — a normal,
     * fast-forward push simulating a concurrent client publishing something else while this
     * test's own client is mid-run, without touching (or even knowing about) anything that
     * client already cloned or wrote.
     */
    private function extendUpstream(string $bareRepo, string $relative, string $content): void
    {
        $seed = TempDir::make('gitremote-extend');
        $this->cleanup[] = $seed;

        $this->git($seed, ['clone', '-q', $bareRepo, '.']);
        $this->git($seed, ['config', 'user.email', 'tests@example.invalid']);
        $this->git($seed, ['config', 'user.name', 'Replay Tests']);

        TempDir::write($seed . '/' . $relative, $content);

        $this->git($seed, ['add', '-A']);
        $this->git($seed, ['commit', '-q', '-m', 'extended']);
        $this->git($seed, ['push', '-q', 'origin', 'main']);
    }

    private function showUpstream(string $bareRepo, string $path): ?string
    {
        $process = new Process(['git', 'show', 'main:' . $path], $bareRepo);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    /** @return list<string> */
    private static function nonEmptyLines(string $output): array
    {
        $lines = preg_split('/\R+/', trim($output), flags: PREG_SPLIT_NO_EMPTY);

        return $lines === false ? [] : $lines;
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
