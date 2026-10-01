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
 * is at least two seconds older than the moment its content was read. On a POSIX file system
 * ctime is set by the kernel on every change and cannot be set by a program, so any later
 * change to the file — including one that forges its mtime — gives it a ctime in a later
 * second than the one recorded, and the entry no longer matches. The second second of margin
 * covers the kernel's coarse clock, which can stamp a change with the previous second. A file
 * changed that recently is simply re-read next time.
 *
 * "The moment its content was read" is the FILE SYSTEM's clock, not this process's: a network
 * file system whose server runs behind the client would otherwise make a file changed a
 * second ago look two seconds old. It is measured once per pass by writing a probe file in
 * the project root ({@see self::probeFileSystem()}), which also tests the premise itself: the
 * probe's mtime is set into the past, and if its ctime follows, ctime is not a kernel-owned
 * change time on this file system (FAT and exFAT, some FUSE mounts such as sshfs) and the
 * pass-to-pass cache is not used at all: every file is re-read. So is it on Windows, where
 * `stat()`'s ctime is the creation time, and wherever the probe cannot be written. The version
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

    /** @var array{offset: int, trusted: bool}|null the file system's clock and ctime, once probed */
    private ?array $fileSystem = null;

    /**
     * @param string|null $cacheFile where hashes persist across passes; null keeps them in this
     *        process only
     * @param Closure(): int|null $clock this process's clock, seconds since the epoch; a test
     *        seam for `time()`. The file system's clock is still probed against it.
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

    /** Now, by the file system's clock, never ahead of it (see the class docblock). */
    private function now(): int
    {
        return $this->processTime() + $this->probeFileSystem()['offset'];
    }

    private function processTime(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    /** Whether the pass-to-pass cache may be read and written on this file system. */
    private function cacheUsable(): bool
    {
        if ($this->cacheFile === null || PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return $this->probeFileSystem()['trusted'];
    }

    /**
     * Writes a probe file in the project root: its mtime is the file system's clock (the
     * offset kept is never positive, so "now" is never later than that clock), and setting that
     * mtime into the past shows whether ctime follows it (then it is no kernel-owned change time
     * and the cache is off). A probe that cannot be written leaves the cache off.
     *
     * @return array{offset: int, trusted: bool}
     */
    private function probeFileSystem(): array
    {
        if ($this->fileSystem !== null) {
            return $this->fileSystem;
        }

        $probe = rtrim($this->projectRoot, '/') . '/.phpunit-replay-clock-' . bin2hex(random_bytes(4));
        $before = $this->processTime();

        if (@file_put_contents($probe, '') === false) {
            return $this->fileSystem = ['offset' => 0, 'trusted' => false];
        }

        try {
            $written = self::rawStat($probe);
            $forged = $written !== null && @touch($probe, $written['mtime'] - 3600) ? self::rawStat($probe) : null;

            if ($written === null || $forged === null) {
                return $this->fileSystem = ['offset' => 0, 'trusted' => false];
            }

            return $this->fileSystem = [
                'offset' => min(0, $written['mtime'] - $before),
                'trusted' => $forged['ctime'] >= $written['mtime'] - 1,
            ];
        } finally {
            @unlink($probe);
        }
    }

    /** @return array{mtime: int, ctime: int}|null */
    private static function rawStat(string $absolute): ?array
    {
        clearstatcache(true, $absolute);
        $stat = @stat($absolute);

        return $stat === false ? null : ['mtime' => (int) $stat['mtime'], 'ctime' => (int) $stat['ctime']];
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

        $cached = $this->cacheUsable() ? ($this->cache[$relative] ?? null) : null;

        if ($cached !== null && array_slice($cached, 0, 5) === $before && is_string($cached[5])) {
            $this->files[$relative] = ['hash' => $cached[5], 'stat' => $before, 'readAt' => $readAt, 'afterStart' => $this->runStart !== null, 'unstable' => false];

            return $cached[5];
        }

        $hash = ContentHash::of($absolute);
        $after = self::statOf($absolute);
        $unstable = $hash === null || $after !== $before;

        $this->files[$relative] = ['hash' => $hash, 'stat' => $before, 'readAt' => $readAt, 'afterStart' => $this->runStart !== null, 'unstable' => $unstable];

        // Racy-clean rule: only a file whose last change is safely in the past is remembered.
        if (! $unstable && $hash !== null && $before[2] <= $readAt - 2 && $this->cacheUsable()) {
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
     * so that a stamp may carry it. Decided by CONTENT: a file whose bytes after the run hash
     * as they did when the pass read them is stable, whatever its mtime or ctime say — a test
     * that rewrites a watched fixture with the same bytes must not unstamp every test under
     * the pattern on every pass. The stat() only saves the re-read: unchanged, and not changed
     * within a second of the read, the content cannot have moved.
     *
     * Two cases are refused. A file first read after the tests started, and changed since
     * shortly before they did: there is no earlier content to compare with. A file whose
     * content really differs now, since the tests may have read either version. The case this
     * cannot see: content changed during the run and changed back to the same bytes before it
     * ended (an A → B → A rewrite); the stamp then says A although some test may have read B.
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

        if ($now === null || $entry['stat'] === null) {
            return $this->stable[$relative] = $now === $entry['stat'];
        }

        if ($entry['afterStart'] && $this->runStart !== null) {
            return $this->stable[$relative] = $now === $entry['stat'] && $now[2] < $this->runStart - 1;
        }

        if ($now === $entry['stat'] && $now[2] < $entry['readAt'] - 1) {
            return $this->stable[$relative] = true;
        }

        return $this->stable[$relative] = ContentHash::of($this->absolute($relative)) === $entry['hash'];
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
