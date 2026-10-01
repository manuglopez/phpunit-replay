<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Closure;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Console\Runner\Warnings;
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
 * ctime is set by the kernel on every change, so any later change to the file — including one
 * that forges its mtime — gives it a ctime in a later second than the one recorded, and the
 * entry no longer matches. The second second of margin covers the kernel's coarse clock, which
 * can stamp a change with the previous second. A file changed that recently is simply re-read
 * next time.
 *
 * **What that rests on, checked rather than assumed** ({@see self::probeFileSystem()}).
 * "The moment its content was read" is the FILE SYSTEM's clock, not this process's: a network
 * file system whose server runs behind the client would otherwise make a file changed a second
 * ago look two seconds old. Once per pass (per instance) a probe file is written beside the project — in
 * the state directory when it is on the project root's device, else in the git directory when
 * that is, never in the working tree — and its mtime gives the offset. Once per device, that
 * probe also tests the premise: its mtime is set into the past, and if its ctime follows, ctime
 * is not a kernel-owned change time there (FAT and exFAT, some FUSE mounts such as sshfs). The
 * verdict is persisted per device number in the cache file, so later passes only measure the
 * clock. Only files on that probed, trusted device are served from or written to the cache,
 * or judged stable by their `stat()`: a file on another mount (`vendor/` bind-mounted, say), on
 * a file system whose ctime can be forged, on Windows (where PHP's `ctime` is the creation
 * time), or with no probe possible at all, is re-read every pass and compared by content. The
 * version token covers `ContentHash`'s own definition and the PHP version (the tokenizer it
 * relies on).
 */
final class FileHashes
{
    private const VERSION = 'content-hash@1';

    /** Basename prefix of the probe file; never part of a tree this package reasons about. */
    public const PROBE_PREFIX = '.phpunit-replay-clock-';

    /**
     * @var array<string, array{hash: ?string, stat: ?list<int>, readAt: int, afterStart: bool, unstable: bool}>
     */
    private array $files = [];

    /** @var array<string, list<int|string>> path => [size, mtime, ctime, ino, dev, hash] */
    private array $cache = [];

    /** @var array<int|string, bool> device number => whether its ctime is kernel-owned (persisted) */
    private array $devices = [];

    private bool $cacheDirty = false;

    private ?int $runStart = null;

    /** @var array<string, bool> this round's answers of {@see self::stable()} */
    private array $stable = [];

    /** @var array{offset: int, dev: ?int, trusted: bool}|null the probed device, its clock and its ctime */
    private ?array $fileSystem = null;

    private static bool $noProbeNoted = false;

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
            [$this->cache, $this->devices] = self::load($cacheFile);
        }
    }

    /** `<stateDir>/content-hashes.json`: the one place the pass-to-pass cache lives. */
    public static function inStateDir(string $projectRoot, string $stateDir): self
    {
        return new self($projectRoot, rtrim($stateDir, '/') . '/content-hashes.json');
    }

    /** Whether `$relative` is a probe file this class writes (the backstop for a crash mid-probe). */
    public static function isProbeFile(string $relative): bool
    {
        return str_starts_with(basename($relative), self::PROBE_PREFIX);
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

    /** Whether a file on device `$dev` may be judged by its `stat()`: probed, and ctime is kernel-owned there. */
    private function deviceTrusted(int $dev): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        $fileSystem = $this->probeFileSystem();

        return $fileSystem['trusted'] && $fileSystem['dev'] === $dev;
    }

    /** Whether the pass-to-pass cache may hold a file on device `$dev`. */
    private function cacheUsable(int $dev): bool
    {
        return $this->cacheFile !== null && $this->deviceTrusted($dev);
    }

    /**
     * Writes a probe file where it may ({@see self::probeDirectory()}): its mtime is the file
     * system's clock (the offset kept is never positive, so "now" is never later than that
     * clock). For a device with no persisted verdict, setting that mtime into the past shows
     * whether ctime follows it (then it is no kernel-owned change time and nothing on it is
     * judged by `stat()`). No place to probe, or a probe that cannot be written, leaves every
     * device untrusted and the process clock as it is.
     *
     * @return array{offset: int, dev: ?int, trusted: bool}
     */
    private function probeFileSystem(): array
    {
        if ($this->fileSystem !== null) {
            return $this->fileSystem;
        }

        $untrusted = ['offset' => 0, 'dev' => null, 'trusted' => false];
        $directory = PHP_OS_FAMILY === 'Windows' ? null : $this->probeDirectory();

        if ($directory === null) {
            if (! self::$noProbeNoted) {
                self::$noProbeNoted = true;
                Warnings::debug('content hashes: no state or git directory on the project\'s device to probe its clock in; every file is re-read each pass');
            }

            return $this->fileSystem = $untrusted;
        }

        $probe = $directory . '/' . self::PROBE_PREFIX . bin2hex(random_bytes(4));
        $before = $this->processTime();

        if (@file_put_contents($probe, '') === false) {
            return $this->fileSystem = $untrusted;
        }

        try {
            $written = self::rawStat($probe);

            if ($written === null) {
                return $this->fileSystem = $untrusted;
            }

            $dev = $written['dev'];
            $trusted = $this->devices[(string) $dev] ?? null;

            if ($trusted === null) {
                $forged = @touch($probe, $written['mtime'] - 3600) ? self::rawStat($probe) : null;

                if ($forged === null) {
                    return $this->fileSystem = $untrusted;
                }

                $trusted = $forged['ctime'] >= $written['mtime'] - 1;
                $this->devices[(string) $dev] = $trusted;
                $this->cacheDirty = true;
            }

            return $this->fileSystem = ['offset' => min(0, $written['mtime'] - $before), 'dev' => $dev, 'trusted' => $trusted];
        } finally {
            @unlink($probe);
        }
    }

    /**
     * Where a probe may be written: a directory this package owns, on the project root's own
     * device (the clock and the ctime semantics measured must be the tree's), never the working
     * tree itself. The state directory first, then the git directory.
     */
    private function probeDirectory(): ?string
    {
        $rootDev = self::deviceOf($this->projectRoot);

        if ($rootDev === null) {
            return null;
        }

        if ($this->cacheFile !== null) {
            $stateDir = dirname($this->cacheFile);

            if (! is_dir($stateDir)) {
                @mkdir($stateDir, 0o775, true);
            }

            if (self::deviceOf($stateDir) === $rootDev) {
                return $stateDir;
            }
        }

        $gitDir = (new Git($this->projectRoot))->output(['rev-parse', '--absolute-git-dir']);

        return $gitDir !== null && self::deviceOf($gitDir) === $rootDev ? $gitDir : null;
    }

    private static function deviceOf(string $directory): ?int
    {
        clearstatcache(true, $directory);
        $stat = @stat($directory);

        return $stat === false || ! is_dir($directory) ? null : (int) $stat['dev'];
    }

    /** @return array{mtime: int, ctime: int, dev: int}|null */
    private static function rawStat(string $absolute): ?array
    {
        clearstatcache(true, $absolute);
        $stat = @stat($absolute);

        return $stat === false ? null : ['mtime' => (int) $stat['mtime'], 'ctime' => (int) $stat['ctime'], 'dev' => (int) $stat['dev']];
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

        $cacheUsable = $this->cacheUsable($before[4]);
        $cached = $cacheUsable ? ($this->cache[$relative] ?? null) : null;

        if ($cached !== null && array_slice($cached, 0, 5) === $before && is_string($cached[5])) {
            $this->files[$relative] = ['hash' => $cached[5], 'stat' => $before, 'readAt' => $readAt, 'afterStart' => $this->runStart !== null, 'unstable' => false];

            return $cached[5];
        }

        $hash = ContentHash::of($absolute);
        $after = self::statOf($absolute);
        $unstable = $hash === null || $after !== $before;

        $this->files[$relative] = ['hash' => $hash, 'stat' => $before, 'readAt' => $readAt, 'afterStart' => $this->runStart !== null, 'unstable' => $unstable];

        // Racy-clean rule: only a file whose last change is safely in the past is remembered.
        if (! $unstable && $hash !== null && $before[2] <= $readAt - 2 && $cacheUsable) {
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
     * the pattern on every pass. The stat() only saves the re-read, on a trusted device:
     * unchanged, and not changed within a second of the read, the content cannot have moved.
     *
     * Two cases are refused. A file first read after the tests started, and changed since
     * shortly before they did, or on a device whose clock and ctime were not checked: there is
     * no earlier content to compare with. A file whose content really differs now, since the
     * tests may have read either version. The case this cannot see: content changed during
     * the run and changed back to the same bytes before it ended (an A → B → A rewrite); the
     * stamp then says A although some test may have read B. A file that never existed is stable
     * while it still does not.
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

        $trusted = $this->deviceTrusted($entry['stat'][4]);

        if ($entry['afterStart'] && $this->runStart !== null) {
            return $this->stable[$relative] = $trusted && $now === $entry['stat'] && $now[2] < $this->runStart - 1;
        }

        if ($trusted && $now === $entry['stat'] && $now[2] < $entry['readAt'] - 1) {
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

        $json = json_encode(['v' => self::version(), 'devices' => (object) $this->devices, 'entries' => (object) $this->cache], JSON_UNESCAPED_SLASHES);

        if ($json !== false && AtomicFile::write($this->cacheFile, $json)) {
            $this->cacheDirty = false;
        }
    }

    /** @return array{array<string, list<int|string>>, array<int|string, bool>} entries, device verdicts */
    private static function load(string $cacheFile): array
    {
        $data = json_decode((string) @file_get_contents($cacheFile), true);

        if (! is_array($data) || ($data['v'] ?? null) !== self::version() || ! is_array($data['entries'] ?? null)) {
            return [[], []];
        }

        $entries = [];

        foreach ($data['entries'] as $path => $entry) {
            if (! is_array($entry) || count($entry) !== 6 || ! array_is_list($entry)) {
                continue;
            }

            [$size, $mtime, $ctime, $ino, $dev, $hash] = $entry;

            if (is_int($size) && is_int($mtime) && is_int($ctime) && is_int($ino) && is_int($dev) && is_string($hash)) {
                $entries[(string) $path] = [$size, $mtime, $ctime, $ino, $dev, $hash];
            }
        }

        $devices = [];

        foreach (is_array($data['devices'] ?? null) ? $data['devices'] : [] as $dev => $trusted) {
            if (is_bool($trusted)) {
                $devices[(string) $dev] = $trusted;
            }
        }

        return [$entries, $devices];
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
