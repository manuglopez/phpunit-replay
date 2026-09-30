<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Closure;
use Manuglopez\Replay\Support\AtomicFile;

/**
 * {@see ContentHash::of()} of project-relative paths: each file read at most once per pass,
 * and across passes only when its `stat()` says it may have changed.
 *
 * **Within a pass.** A pass needs the same hashes several times: the content key and the
 * non-edge digest of every test file it might serve (`Select\StampAudit`), and both again for
 * the stamps of what it executed (`GraphUpdater`) and what it publishes. The first read wins:
 * a stamp describes the tree as the pass found it when it first looked at a file, which for
 * everything it validates is before the tests started. {@see self::stable()} is what a stamp
 * asks before it is written: whether that content is still what is on disk and what the tests
 * ran on.
 *
 * **Across passes** (`$cacheFile`, `<stateDir>/content-hashes.json`). Every run is a new
 * process, so without this the memo is always cold, and tokenising every dependency costs
 * most of a no-change run on a large project. An entry is the hash plus the `stat()` it was
 * taken under — size, mtime, ctime, inode, device — and is used only when the current `stat()`
 * equals it field for field. It is sound the way git's racy-clean index rule is, adapted to the
 * one-second timestamps PHP's `stat()` returns: an entry is written only when the file's ctime
 * is at least two seconds older than the moment its content was read. Any later change to the
 * file — including one that forges its mtime, since ctime is set by the kernel and cannot be —
 * gives it a ctime in a later second than the one recorded, so the entry no longer matches.
 * The second second of margin covers the kernel's coarse clock, which can stamp a change with
 * the previous second. A file changed that recently is simply re-read next time. The version
 * token covers `ContentHash`'s own definition and the PHP version (the tokenizer it relies on).
 */
final class FileHashes
{
    private const VERSION = 'content-hash@1';

    /**
     * @var array<string, array{hash: ?string, stat: ?list<int>, readAt: int, afterStart: bool, unstable: bool}>
     */
    private array $files = [];

    /** @var array<string, list<int|string>> path => [size, mtime, ctime, ino, dev, hash] */
    private array $cache = [];

    private bool $cacheDirty = false;

    private ?int $runStart = null;

    /** @var array<string, bool> this round's answers of {@see self::stable()} */
    private array $stable = [];

    /**
     * @param string|null $cacheFile where hashes persist across passes; null keeps them in this
     *        process only
     * @param Closure(): int|null $clock seconds since the epoch; a test seam, `time()` otherwise
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $cacheFile = null,
        private readonly ?Closure $clock = null,
    ) {
        if ($cacheFile !== null) {
            $this->cache = self::load($cacheFile);
        }
    }

    /** `<stateDir>/content-hashes.json`: the one place the pass-to-pass cache lives. */
    public static function inStateDir(string $projectRoot, string $stateDir): self
    {
        return new self($projectRoot, rtrim($stateDir, '/') . '/content-hashes.json');
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** Null when the file does not exist or cannot be read. */
    public function of(string $relative): ?string
    {
        if (isset($this->files[$relative])) {
            return $this->files[$relative]['hash'];
        }

        $absolute = $this->absolute($relative);
        $readAt = $this->now();
        $before = self::statOf($absolute);

        if ($before === null) {
            $this->files[$relative] = ['hash' => null, 'stat' => null, 'readAt' => $readAt, 'afterStart' => $this->runStart !== null, 'unstable' => false];

            return null;
        }

        $cached = $this->cache[$relative] ?? null;

        if ($cached !== null && array_slice($cached, 0, 5) === $before && is_string($cached[5])) {
            $this->files[$relative] = ['hash' => $cached[5], 'stat' => $before, 'readAt' => $readAt, 'afterStart' => $this->runStart !== null, 'unstable' => false];

            return $cached[5];
        }

        $hash = ContentHash::of($absolute);
        $after = self::statOf($absolute);
        $unstable = $hash === null || $after !== $before;

        $this->files[$relative] = ['hash' => $hash, 'stat' => $before, 'readAt' => $readAt, 'afterStart' => $this->runStart !== null, 'unstable' => $unstable];

        // Racy-clean rule: only a file whose last change is safely in the past is remembered.
        if (! $unstable && $hash !== null && $before[2] <= $readAt - 2) {
            $this->cache[$relative] = [...$before, $hash];
            $this->cacheDirty = true;
        } elseif (isset($this->cache[$relative])) {
            unset($this->cache[$relative]);
            $this->cacheDirty = true;
        }

        return $hash;
    }

    /**
     * The tests are about to run: a file first read from now on was read after they may have
     * used it, see {@see self::stable()}. `$tree` is the working tree's files (`Git::
     * workingTreeFiles()`): the ones changed in the last two seconds are read now.
     *
     * @param list<string> $tree
     */
    public function markRunStart(array $tree = []): void
    {
        // A file changed within the last two seconds cannot be told apart, afterwards, from
        // one changed while the tests ran: its content is read now, while it is still the
        // content they are about to run on (a `record` right after an edit, or a checkout).
        $now = $this->now();

        foreach ($tree as $relative) {
            if (! isset($this->files[$relative])) {
                $stat = self::statOf($this->absolute($relative));

                if ($stat !== null && $stat[2] >= $now - 2) {
                    $this->of($relative);
                }
            }
        }

        $this->runStart = $now;
        $this->stable = [];
    }

    /**
     * Whether the hash {@see self::of()} gave for this file is the content the tests ran on,
     * so that a stamp may carry it. The file must not have changed since it was read (same
     * `stat()`, and for one changed within a second of being read, the same content now), and
     * a file first read after the tests started must not have changed since before they did.
     * A file that never existed is stable while it still does not.
     */
    public function stable(string $relative): bool
    {
        if (isset($this->stable[$relative])) {
            return $this->stable[$relative];
        }

        $this->of($relative);
        $entry = $this->files[$relative];

        if ($entry['unstable']) {
            return $this->stable[$relative] = false;
        }

        $now = self::statOf($this->absolute($relative));

        if ($now !== $entry['stat']) {
            return $this->stable[$relative] = false;
        }

        if ($now === null) {
            return $this->stable[$relative] = true;
        }

        $ctime = $now[2];

        if ($entry['afterStart'] && $this->runStart !== null && $ctime >= $this->runStart - 1) {
            return $this->stable[$relative] = false;
        }

        // Changed within a second of being read: the same stat() cannot prove the content did
        // not change again in that second, the content itself can.
        if ($ctime >= $entry['readAt'] - 1) {
            return $this->stable[$relative] = ContentHash::of($this->absolute($relative)) === $entry['hash'];
        }

        return $this->stable[$relative] = true;
    }

    /** Writes what this pass learnt for the next one. */
    public function save(): void
    {
        if ($this->cacheFile === null || ! $this->cacheDirty) {
            return;
        }

        $json = json_encode(['v' => self::version(), 'entries' => $this->cache], JSON_UNESCAPED_SLASHES);

        if ($json !== false && AtomicFile::write($this->cacheFile, $json)) {
            $this->cacheDirty = false;
        }
    }

    /** @return array<string, list<int|string>> */
    private static function load(string $cacheFile): array
    {
        $data = json_decode((string) @file_get_contents($cacheFile), true);

        if (! is_array($data) || ($data['v'] ?? null) !== self::version() || ! is_array($data['entries'] ?? null)) {
            return [];
        }

        $entries = [];

        foreach ($data['entries'] as $path => $entry) {
            if (! is_string($path) || ! is_array($entry) || count($entry) !== 6 || ! array_is_list($entry)) {
                continue;
            }

            [$size, $mtime, $ctime, $ino, $dev, $hash] = $entry;

            if (is_int($size) && is_int($mtime) && is_int($ctime) && is_int($ino) && is_int($dev) && is_string($hash)) {
                $entries[$path] = [$size, $mtime, $ctime, $ino, $dev, $hash];
            }
        }

        return $entries;
    }

    private static function version(): string
    {
        return self::VERSION . '/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /** @return list<int>|null size, mtime, ctime, inode, device */
    private static function statOf(string $absolute): ?array
    {
        clearstatcache(true, $absolute);
        $stat = @stat($absolute);

        if ($stat === false || ! is_file($absolute)) {
            return null;
        }

        return [(int) $stat['size'], (int) $stat['mtime'], (int) $stat['ctime'], (int) $stat['ino'], (int) $stat['dev']];
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->projectRoot, '/') . '/' . $relative;
    }
}
