<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class TempDir
{
    public static function make(string $prefix = 'replay'): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpunit-replay-tests';

        if (! is_dir($base) && ! @mkdir($base, 0o775, true) && ! is_dir($base)) {
            throw new RuntimeException('Cannot create ' . $base);
        }

        $dir = $base . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(6));

        if (! @mkdir($dir, 0o775, true)) {
            throw new RuntimeException('Cannot create ' . $dir);
        }

        $real = realpath($dir);

        return $real === false ? $dir : $real;
    }

    public static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    public static function write(string $path, string $content): void
    {
        $parent = dirname($path);

        if (! is_dir($parent) && ! @mkdir($parent, 0o775, true) && ! is_dir($parent)) {
            throw new RuntimeException('Cannot create ' . $parent);
        }

        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Cannot write ' . $path);
        }
    }

    /**
     * Copies a directory tree. Never follows a symlinked entry into its target (which would
     * either recurse forever on a self-referential path — e.g. a composer path-repo symlink
     * whose target contains the very fixture being copied — or, for a *relative* symlink,
     * resolve to the wrong place once recreated at a different depth): a symlink is instead
     * recreated as a symlink pointing at the same *absolute*, fully-resolved target.
     *
     * @param  list<string>  $exclude  top-level relative paths (e.g. "vendor") to skip entirely
     */
    public static function copyTree(string $from, string $to, array $exclude = []): void
    {
        if (! is_dir($to) && ! @mkdir($to, 0o775, true) && ! is_dir($to)) {
            throw new RuntimeException('Cannot create ' . $to);
        }

        self::copyTreeRecursive($from, $to, $exclude, '');
    }

    /** @param list<string> $exclude */
    private static function copyTreeRecursive(string $from, string $to, array $exclude, string $prefix): void
    {
        $entries = scandir($from);

        if ($entries === false) {
            throw new RuntimeException('Cannot read ' . $from);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;

            if (self::isExcluded($relative, $exclude)) {
                continue;
            }

            $source = $from . DIRECTORY_SEPARATOR . $entry;
            $target = $to . DIRECTORY_SEPARATOR . $entry;

            if (is_link($source)) {
                $resolved = realpath($source);
                $linkTarget = $resolved === false ? readlink($source) : $resolved;

                if ($linkTarget === false || ! @symlink($linkTarget, $target)) {
                    throw new RuntimeException('Cannot symlink ' . $target . ' to ' . $source);
                }

                continue;
            }

            if (is_dir($source)) {
                if (! is_dir($target) && ! @mkdir($target, 0o775, true) && ! is_dir($target)) {
                    throw new RuntimeException('Cannot create ' . $target);
                }

                self::copyTreeRecursive($source, $target, $exclude, $relative);

                continue;
            }

            if (! @copy($source, $target)) {
                throw new RuntimeException('Cannot copy ' . $source);
            }
        }
    }

    /** @param list<string> $exclude */
    private static function isExcluded(string $relative, array $exclude): bool
    {
        foreach ($exclude as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }
}
