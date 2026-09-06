<?php

declare(strict_types=1);

namespace Orlegitech\Replay\Tests\Support;

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

    public static function copyTree(string $from, string $to): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        if (! is_dir($to) && ! @mkdir($to, 0o775, true) && ! is_dir($to)) {
            throw new RuntimeException('Cannot create ' . $to);
        }

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($from) + 1);
            $target = $to . DIRECTORY_SEPARATOR . $relative;

            if ($entry->isDir()) {
                if (! is_dir($target) && ! @mkdir($target, 0o775, true) && ! is_dir($target)) {
                    throw new RuntimeException('Cannot create ' . $target);
                }

                continue;
            }

            if (! @copy($entry->getPathname(), $target)) {
                throw new RuntimeException('Cannot copy ' . $entry->getPathname());
            }
        }
    }
}
