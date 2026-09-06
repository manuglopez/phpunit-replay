<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Support;

/**
 * Converts absolute filesystem paths into project-relative paths with forward-slash
 * separators, and back. All paths handed between components are project-relative with
 * forward slashes unless a parameter is explicitly named `$absolute` (docs/INTERNALS.md).
 */
final class Paths
{
    /**
     * null when the path is outside the project root, under vendor/, empty, the literal
     * string 'unknown', or PHP's "eval()'d code" pseudo-path. Relative input (i.e. not
     * absolute) is normalised (leading './' stripped, '\\' converted to '/') and returned
     * as-is, still subject to the same vendor/empty checks.
     */
    public static function relative(string $projectRoot, string $path): ?string
    {
        $normalizedPath = self::normalizeSeparators($path);

        if ($normalizedPath === '' || $normalizedPath === 'unknown') {
            return null;
        }

        if (str_contains($normalizedPath, "eval()'d code")) {
            return null;
        }

        $root = rtrim(self::normalizeSeparators($projectRoot), '/');

        if (self::isAbsolute($normalizedPath)) {
            if ($normalizedPath === $root || ! str_starts_with($normalizedPath, $root . '/')) {
                return null;
            }

            $relative = substr($normalizedPath, strlen($root) + 1);
        } else {
            $relative = $normalizedPath;

            while (str_starts_with($relative, './')) {
                $relative = substr($relative, 2);
            }
        }

        $relative = trim($relative, '/');

        if ($relative === '' || $relative === 'vendor' || str_starts_with($relative, 'vendor/')) {
            return null;
        }

        return $relative;
    }

    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    public static function normalizeSeparators(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function join(string $root, string $relative): string
    {
        $root = rtrim(self::normalizeSeparators($root), '/');
        $relative = ltrim(self::normalizeSeparators($relative), '/');

        return $relative === '' ? $root : $root . '/' . $relative;
    }
}
