<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

use FilesystemIterator;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Remote cache backed by a dedicated (non-code) git repository (SPEC.md §9, docs/INTERNALS.md
 * "GitRemoteCache — automatic maintenance", docs/DECISIONS.md D-038). Keeps a shallow single-branch
 * mirror on disk under `<stateDir>/remote/git/`; `get`/`has`/`keys`/`delete` read/write that
 * working tree directly, `begin()` refreshes it (clone or fetch+reset) and `end()` commits and
 * pushes whatever was written.
 *
 * Reconciling a rejected push (or a mirror that was bootstrapped locally, see
 * {@see self::initMirror()}) NEVER rebases: a shallow (`--depth 1`) fetch severs the parent
 * link of whatever commit it retrieves, so two mirrors that are in fact both descendants of
 * the same history can look "unrelated" to `git rebase` purely because of that truncation —
 * observed in practice as a flaky "unresolved rebase conflict outside graph/**" on CI,
 * depending on git's version and exactly how each client's history happened to diverge.
 * Instead, {@see self::adoptFetchedHistory()} adopts whatever is upstream wholesale
 * (`git reset --hard FETCH_HEAD`) and re-writes every key THIS run's {@see self::put()}
 * calls buffered on top of it, then commits and retries the push. This never needs to
 * reconcile anything path-by-path: objects are content-addressed and append-only (writing
 * one again is a harmless no-op) and `graph/**` is "ours wins" by construction, since our
 * own buffered write simply overwrites it again after adopting upstream.
 *
 * Accepted URL forms: `git+ssh://…`, `git+https://…` (the `git+` prefix is stripped before
 * being handed to git), plain `ssh://…`, the scp-like `git@host:path.git`, `https://….git`,
 * and — for tests — a local filesystem path or `file://` URL pointing at a bare repository.
 *
 * Never throws: every environmental failure (missing/unreachable remote, lock contention,
 * a push that keeps getting rejected) is recorded through {@see self::lastError()} and the
 * caller is expected to warn and continue with whatever the local mirror already has.
 */
final class GitRemoteCache implements RemoteCache
{
    private readonly string $url;

    private readonly string $branch;

    private ?string $lastError = null;

    private bool $dirty = false;

    private ?string $commitLabel = null;

    /**
     * True once {@see self::initMirror()} has bootstrapped this mirror locally (the branch
     * did not exist upstream at `begin()` time — an orphan `replay: init` root, or no mirror
     * at all when the remote was unreachable): `end()` re-checks upstream right before
     * pushing, since another client may have created the branch in the meantime.
     */
    private bool $locallyInitialised = false;

    /**
     * Everything {@see self::put()} wrote during this run, key => body: replayed onto the
     * mirror by {@see self::adoptFetchedHistory()} after a `git reset --hard` discards
     * whatever the local (rejected, or pre-emptively suspect) history had staged or
     * committed for these same paths.
     *
     * @var array<string, string>
     */
    private array $buffer = [];

    public function __construct(
        string $url,
        private readonly string $mirrorDir,
        string $branch = 'main',
        private readonly int $refreshSeconds = 300,
        private readonly int $timeout = 60,
    ) {
        $this->url = self::resolveUrl($url);
        $this->branch = $branch === '' ? 'main' : $branch;
    }

    /**
     * Builds an instance from the package Config: `$config->remote` is the URL, the mirror
     * lives at `<stateDir>/remote/git`, and `remoteBranch` / `remoteRefreshSeconds` /
     * `remoteTimeout` are read straight off the Config object.
     */
    public static function fromConfig(Config $config, string $stateDir): self
    {
        $mirrorDir = rtrim(Paths::normalizeSeparators($stateDir), '/') . '/remote/git';

        return new self(
            $config->remote ?? '',
            $mirrorDir,
            $config->remoteBranch,
            $config->remoteRefreshSeconds,
            $config->remoteTimeout,
        );
    }

    /** Overrides the default `replay: +N objects` commit message used by {@see self::end()}. */
    public function setCommitLabel(string $label): void
    {
        $this->commitLabel = $label;
    }

    public function begin(): void
    {
        $this->lastError = null;

        $handle = $this->acquireLock();

        try {
            if (! $this->isCloned()) {
                $this->establishMirror();

                return;
            }

            if ($this->markerAge() >= $this->refreshSeconds) {
                $this->refresh();
            }
        } finally {
            $this->releaseLock($handle);
        }
    }

    public function end(): void
    {
        if (! $this->dirty) {
            return;
        }

        $handle = $this->acquireLock();

        try {
            $this->doEnd();
        } finally {
            $this->releaseLock($handle);
            $this->dirty = false;
            $this->buffer = [];
        }
    }

    public function get(string $key): ?string
    {
        return AtomicFile::read($this->pathFor($key));
    }

    public function put(string $key, string $body): bool
    {
        if (AtomicFile::write($this->pathFor($key), $body)) {
            $this->dirty = true;
            $this->buffer[$key] = $body;

            return true;
        }

        $this->lastError = 'failed to write ' . $key;

        return false;
    }

    public function has(string $key): bool
    {
        return is_file($this->pathFor($key));
    }

    /**
     * @return list<string>
     */
    public function keys(string $prefix): array
    {
        if (! is_dir($this->mirrorDir)) {
            return [];
        }

        $base = rtrim(Paths::normalizeSeparators($this->mirrorDir), '/');
        $prefix = ltrim($prefix, '/');
        $results = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->mirrorDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->isDir()) {
                continue;
            }

            $path = Paths::normalizeSeparators($entry->getPathname());
            $relative = ltrim(substr($path, strlen($base)), '/');

            if ($relative === '.git' || str_starts_with($relative, '.git/')) {
                continue;
            }

            if ($prefix === '' || str_starts_with($relative, $prefix)) {
                $results[] = $relative;
            }
        }

        sort($results);

        return $results;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);

        if (! is_file($path)) {
            return true;
        }

        if (@unlink($path)) {
            $this->dirty = true;

            return true;
        }

        $this->lastError = 'failed to delete ' . $key;

        return false;
    }

    public function name(): string
    {
        return 'git';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Rewrites the mirror's branch as a single orphan commit of its current content and
     * force-pushes it (`prune --remote --squash`, git backend only). The clients detect the
     * rewritten history on their next {@see self::begin()} and re-clone.
     */
    public function squash(): bool
    {
        if (! $this->isCloned()) {
            $this->lastError = 'cannot squash: no local mirror';

            return false;
        }

        $handle = $this->acquireLock();

        try {
            $git = $this->mirrorGit();

            $checkout = $git->result(['checkout', '-q', '--orphan', 'replay-squash-tmp']);

            if ($checkout['exitCode'] !== 0) {
                $this->lastError = 'squash checkout failed: exit ' . $checkout['exitCode'];

                return false;
            }

            $git->result(['add', '-A']);

            $commit = $git->result([
                '-c', 'user.name=phpunit-replay',
                '-c', 'user.email=phpunit-replay@localhost',
                '-c', 'commit.gpgsign=false',
                'commit', '-q', '--allow-empty', '-m', $this->commitLabel ?? 'replay: squash',
            ]);

            if ($commit['exitCode'] !== 0) {
                $this->lastError = 'squash commit failed: exit ' . $commit['exitCode'];

                return false;
            }

            $git->result(['branch', '-M', $this->branch]);

            $push = $git->result(['push', '--force', 'origin', $this->branch]);

            if ($push['exitCode'] !== 0) {
                $this->lastError = 'squash push failed: exit ' . $push['exitCode'];

                return false;
            }

            $this->touchMarker();
            $this->lastError = null;

            return true;
        } finally {
            $this->releaseLock($handle);
        }
    }

    public function remoteUrl(): string
    {
        return $this->url;
    }

    public function branch(): string
    {
        return $this->branch;
    }

    public function mirrorDir(): string
    {
        return $this->mirrorDir;
    }

    private function doEnd(): void
    {
        if (! $this->isCloned()) {
            $this->lastError = 'remote push failed: no local mirror';

            return;
        }

        $git = $this->mirrorGit();

        if ($this->locallyInitialised) {
            // Re-check upstream right before pushing: this mirror was bootstrapped locally
            // because the branch did not exist at begin() time, but another client may have
            // created it since — adopt it now rather than let the push get rejected only to
            // reconcile a moment later anyway.
            $this->adoptUpstreamIfItNowExists($git);
        }

        $this->commitStagedChanges($git);
        $this->pushWithRetry($git);
    }

    /**
     * Silently does nothing when the branch genuinely still does not exist upstream (the
     * fetch fails with "couldn't find remote ref" or similar) — that failure is expected and
     * not an error here, unlike the same fetch inside {@see self::pushWithRetry()}, which
     * only ever runs after a push was actually rejected and therefore already knows the
     * branch exists.
     */
    private function adoptUpstreamIfItNowExists(Git $git): void
    {
        $fetch = $git->result(['fetch', '--depth', '1', 'origin', $this->branch]);

        if ($fetch['exitCode'] !== 0) {
            return;
        }

        $this->adoptFetchedHistory($git);
    }

    private function pushWithRetry(Git $git): void
    {
        $deadline = microtime(true) + max(1, $this->timeout);
        $attempts = 0;

        while ($attempts < 3) {
            if (microtime(true) >= $deadline) {
                $this->lastError = sprintf('remote push timed out after %ds', $this->timeout);

                return;
            }

            $push = $git->result(['push', 'origin', 'HEAD:' . $this->branch]);

            if ($push['exitCode'] === 0) {
                $this->lastError = null;

                return;
            }

            $attempts++;

            // Never rebase (see the class docblock): adopt whatever is upstream now and
            // re-write this run's own buffered keys on top of it, then commit and retry.
            $fetch = $git->result(['fetch', '--depth', '1', 'origin', $this->branch]);

            if ($fetch['exitCode'] !== 0) {
                $this->lastError = 'git push rejected and fetch failed: exit ' . $fetch['exitCode'];

                return;
            }

            $this->adoptFetchedHistory($git);
            $this->commitStagedChanges($git);
        }

        $this->lastError = 'git push rejected after 3 retries';
    }

    /**
     * Adopts whatever `FETCH_HEAD` now holds wholesale (`git reset --hard`, discarding
     * whatever this mirror had committed or staged for the same paths) and re-writes every
     * key {@see self::put()} buffered during this run on top of it — objects are
     * content-addressed and append-only (re-writing one is a no-op) and `graph/**` is "ours
     * wins" by construction, since our own write simply overwrites it again. The caller
     * commits the result ({@see self::commitStagedChanges()}) and retries the push.
     */
    private function adoptFetchedHistory(Git $git): void
    {
        $git->result(['reset', '--hard', 'FETCH_HEAD']);

        foreach ($this->buffer as $key => $body) {
            AtomicFile::write($this->pathFor($key), $body);
        }
    }

    private function commitStagedChanges(Git $git): void
    {
        $git->result(['add', '-A']);

        $diff = $git->result(['diff', '--cached', '--quiet']);
        $hasStaged = $diff['exitCode'] !== 0;

        if (! $hasStaged) {
            return;
        }

        $count = $this->countStagedFiles($git);
        $message = $this->commitLabel ?? sprintf('replay: +%d objects', $count);

        $git->result([
            '-c', 'user.name=phpunit-replay',
            '-c', 'user.email=phpunit-replay@localhost',
            '-c', 'commit.gpgsign=false',
            'commit', '-q', '-m', $message,
        ]);
    }

    private function countStagedFiles(Git $git): int
    {
        $output = $git->raw(['diff', '--cached', '--name-only']);

        return $output === null ? 0 : count(self::splitLines($output));
    }

    private function establishMirror(): void
    {
        $this->wipeMirror();

        $parent = dirname($this->mirrorDir);

        if (! is_dir($parent)) {
            @mkdir($parent, 0o775, true);
        }

        $clone = (new Git($parent, (float) max(1, $this->timeout)))->result([
            'clone', '--depth', '1', '--single-branch', '--branch', $this->branch,
            $this->url, $this->mirrorDir,
        ]);

        if ($clone['exitCode'] === 0) {
            $this->touchMarker();

            return;
        }

        if ($this->remoteReachable()) {
            $this->initMirror(true);
            $this->touchMarker();

            return;
        }

        $this->lastError = 'git clone failed: exit ' . $clone['exitCode'];
        $this->initMirror(false);
    }

    private function refresh(): void
    {
        $git = $this->mirrorGit();

        $fetch = $git->result(['fetch', '--depth', '1', 'origin', $this->branch]);

        if ($fetch['exitCode'] !== 0) {
            if ($this->remoteReachable()) {
                $this->establishMirror();

                return;
            }

            $this->lastError = 'git fetch failed: exit ' . $fetch['exitCode'];

            return;
        }

        $git->result(['reset', '--hard', 'FETCH_HEAD']);
        $this->touchMarker();
    }

    private function initMirror(bool $withPlaceholderCommit): void
    {
        $this->locallyInitialised = true;

        if (! is_dir($this->mirrorDir)) {
            @mkdir($this->mirrorDir, 0o775, true);
        }

        $git = $this->mirrorGit();
        $git->result(['init', '-q', '-b', $this->branch]);
        $git->result(['remote', 'add', 'origin', $this->url]);

        if ($withPlaceholderCommit) {
            $git->result([
                '-c', 'user.name=phpunit-replay',
                '-c', 'user.email=phpunit-replay@localhost',
                '-c', 'commit.gpgsign=false',
                'commit', '-q', '--allow-empty', '-m', 'replay: init',
            ]);
        }
    }

    private function remoteReachable(): bool
    {
        return (new Git(null, 5.0))->succeeds(['ls-remote', $this->url]);
    }

    private function isCloned(): bool
    {
        return is_dir($this->mirrorDir . '/.git');
    }

    private function mirrorGit(): Git
    {
        return new Git($this->mirrorDir, (float) max(1, $this->timeout));
    }

    private function pathFor(string $key): string
    {
        $normalized = ltrim(Paths::normalizeSeparators($key), '/');

        return rtrim($this->mirrorDir, '/') . '/' . $normalized;
    }

    private function markerPath(): string
    {
        return rtrim($this->mirrorDir, '/') . '.refreshed';
    }

    private function touchMarker(): void
    {
        @file_put_contents($this->markerPath(), (string) time());
    }

    private function markerAge(): int
    {
        $content = AtomicFile::read($this->markerPath());

        if ($content === null) {
            return PHP_INT_MAX;
        }

        $timestamp = (int) trim($content);

        return max(0, time() - $timestamp);
    }

    private function wipeMirror(): void
    {
        if (! is_dir($this->mirrorDir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->mirrorDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($this->mirrorDir);
    }

    /**
     * @return resource|null
     */
    private function acquireLock(): mixed
    {
        $path = rtrim($this->mirrorDir, '/') . '.lock';
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0o775, true) && ! is_dir($dir)) {
            return null;
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            return null;
        }

        $deadline = microtime(true) + 30.0;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                $this->lastError = 'timed out waiting for the remote cache lock; proceeding without it';

                break;
            }

            usleep(100_000);
        }

        return $handle;
    }

    /**
     * @param resource|null $handle
     */
    private function releaseLock(mixed $handle): void
    {
        if ($handle === null) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function resolveUrl(string $url): string
    {
        return str_starts_with($url, 'git+') ? substr($url, 4) : $url;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $output): array
    {
        $lines = preg_split('/\R+/', trim($output), flags: PREG_SPLIT_NO_EMPTY);

        return $lines === false ? [] : $lines;
    }
}
