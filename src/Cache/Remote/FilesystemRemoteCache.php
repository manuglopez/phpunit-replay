<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

use FilesystemIterator;
use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A remote cache that is just a directory: an NFS mount, an `rclone mount`, a shared
 * volume, or a plain folder on the same machine (SPEC.md §9, docs/INTERNALS.md "Phase 3
 * contracts — distribution"). Keys map one-to-one onto paths under the root, writes go
 * through {@see AtomicFile} so a reader never sees a half-written object, and `keys()`
 * is a directory scan — which is what makes this (and the git backend) the only two
 * backends `prune --remote` can garbage-collect.
 *
 * Nothing here throws: every failure is recorded in {@see self::lastError()} and reported
 * as `false`/`null`, so a broken mount degrades the run to local-only rather than ending it.
 */
final class FilesystemRemoteCache implements RemoteCache
{
    private ?string $lastError = null;

    public function __construct(private readonly string $root)
    {
    }

    /**
     * Accepts `file:///absolute/path` (the documented form), `file://localhost/absolute/path`,
     * or a bare absolute path. Returns null for anything else — the factory then falls through
     * to the next backend.
     */
    public static function fromRemote(string $remote): ?self
    {
        $remote = trim($remote);

        if ($remote === '') {
            return null;
        }

        if (stripos($remote, 'file://') === 0) {
            $path = substr($remote, 7);

            // file://localhost/tmp/x and file:///tmp/x both mean /tmp/x.
            if (stripos($path, 'localhost/') === 0) {
                $path = substr($path, 9);
            }

            $path = rawurldecode($path);

            return $path === '' ? null : new self(self::normalize($path));
        }

        return Paths::isAbsolute($remote) ? new self(self::normalize($remote)) : null;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function begin(): void
    {
        $this->lastError = null;

        if (! is_dir($this->root) && ! @mkdir($this->root, 0o775, true) && ! is_dir($this->root)) {
            $this->lastError = 'cannot create ' . $this->root;
        }
    }

    public function end(): void
    {
    }

    public function get(string $key): ?string
    {
        $path = $this->pathFor($key);

        if ($path === null) {
            return null;
        }

        return AtomicFile::read($path);
    }

    public function put(string $key, string $body): bool
    {
        $path = $this->pathFor($key);

        if ($path === null) {
            return false;
        }

        if (! AtomicFile::write($path, $body)) {
            $this->lastError = 'cannot write ' . $key;

            return false;
        }

        return true;
    }

    public function has(string $key): bool
    {
        $path = $this->pathFor($key);

        return $path !== null && is_file($path);
    }

    public function keys(string $prefix): array
    {
        $prefix = ltrim(Paths::normalizeSeparators($prefix), '/');

        if (self::isUnsafe($prefix)) {
            $this->lastError = 'unsafe key prefix';

            return [];
        }

        $full = rtrim($this->root . '/' . $prefix, '/');

        if (is_dir($full)) {
            $keys = $this->scan($full);
        } else {
            $keys = $this->scan(dirname($full));
            $keys = array_values(array_filter($keys, static fn (string $key): bool => str_starts_with($key, $prefix)));
        }

        sort($keys);

        return $keys;
    }

    public function delete(string $key): bool
    {
        $path = $this->pathFor($key);

        if ($path === null) {
            return false;
        }

        if (! is_file($path)) {
            return true;
        }

        if (! @unlink($path)) {
            $this->lastError = 'cannot delete ' . $key;

            return false;
        }

        return true;
    }

    public function name(): string
    {
        return 'file';
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @return list<string> keys (root-relative, forward slashes) of every file under `$directory` */
    private function scan(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $root = rtrim($this->root, '/') . '/';
        $keys = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || ! $entry->isFile()) {
                continue;
            }

            $path = Paths::normalizeSeparators($entry->getPathname());

            if (str_starts_with($path, $root)) {
                $keys[] = substr($path, strlen($root));
            }
        }

        return $keys;
    }

    /** Null for a key that would escape the root (`..`, absolute, empty). */
    private function pathFor(string $key): ?string
    {
        $key = ltrim(Paths::normalizeSeparators($key), '/');

        if (self::isUnsafe($key) || $key === '') {
            $this->lastError = 'unsafe key ' . $key;

            return null;
        }

        return rtrim($this->root, '/') . '/' . $key;
    }

    private static function isUnsafe(string $key): bool
    {
        return $key === '..' || str_starts_with($key, '../') || str_contains($key, '/../') || str_ends_with($key, '/..');
    }

    private static function normalize(string $path): string
    {
        return rtrim(Paths::normalizeSeparators($path), '/');
    }
}
