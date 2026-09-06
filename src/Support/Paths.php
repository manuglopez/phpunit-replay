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
        $realRoot = @realpath($projectRoot);
        if ($realRoot !== false) {
            $realRoot = rtrim(self::normalizeSeparators($realRoot), '/');
        } else {
            // If realpath fails, try to normalize the root by resolving . and .. segments
            $realRoot = self::normalizeDotSegments($root);
            if ($realRoot === $root) {
                // No normalization happened, so realRoot stays null
                $realRoot = null;
            }
        }

        if (self::isAbsolute($normalizedPath)) {
            $isUnderRoot = $normalizedPath !== $root && str_starts_with($normalizedPath, $root . '/');
            $isUnderRealRoot = $realRoot !== null && $normalizedPath !== $realRoot && str_starts_with($normalizedPath, $realRoot . '/');
            $activeRoot = $root;

            if (! $isUnderRoot && ! $isUnderRealRoot) {
                // Try realpath if the normalized path is not under either root
                $real = @realpath($path);
                if ($real !== false) {
                    $normalizedReal = rtrim(self::normalizeSeparators($real), '/');
                    $isUnderRoot = $normalizedReal !== $root && str_starts_with($normalizedReal, $root . '/');
                    $isUnderRealRoot = $realRoot !== null && $normalizedReal !== $realRoot && str_starts_with($normalizedReal, $realRoot . '/');

                    if (! $isUnderRoot && ! $isUnderRealRoot) {
                        return null;
                    }

                    $normalizedPath = $normalizedReal;
                } else {
                    return null;
                }
            }

            // Use realRoot if path is under it but not under root
            if ($isUnderRealRoot && ! $isUnderRoot && $realRoot !== null) {
                $activeRoot = $realRoot;
            }

            $relative = substr($normalizedPath, strlen($activeRoot) + 1);
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

    private static function normalizeDotSegments(string $path): string
    {
        if (! str_contains($path, '.' . '/') && ! str_contains($path, '/' . '.')) {
            return $path;
        }

        $parts = explode('/', $path);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '.' || $part === '') {
                // Skip '.' and empty segments (except for leading empty on absolute paths)
                if ($part === '' && count($result) === 0) {
                    $result[] = '';
                }
                continue;
            }

            if ($part === '..') {
                // Go up one level
                if (count($result) > 0 && $result[count($result) - 1] !== '') {
                    array_pop($result);
                }
                continue;
            }

            $result[] = $part;
        }

        $normalized = implode('/', $result);

        return $normalized === '' ? '/' : $normalized;
    }
}
