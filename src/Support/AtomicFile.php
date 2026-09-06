<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Support;

/**
 * Crash-safe file writes: write to a sibling temp file, then rename over the target.
 * A `rename()` on the same filesystem is atomic, so a process killed mid-write never
 * leaves a corrupt file at `$path` — either the old content or the new one is there.
 */
final class AtomicFile
{
    /**
     * Creates parent directories as needed. Writes to "<path>.<8hex>.tmp" then renames it
     * over `$path`. Returns false on any failure (the temp file is removed on failure).
     */
    public static function write(string $path, string $content): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            return false;
        }

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }

        if (! @rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /** null when the file is missing or unreadable. */
    public static function read(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }
}
