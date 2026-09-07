<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/ContentHash.php
 *
 * Deviation from Pest (SPEC §4.4): `ofContent()` takes `(string $content, string $pathForType)`
 * — Pest's original is `(string $path, string $raw)`. `of()` also returns `?string` (null when
 * unreadable) instead of Pest's `string|false`.
 */
final class ContentHash
{
    /** null when the file does not exist or is not readable. */
    public static function of(string $absolute): ?string
    {
        $raw = @file_get_contents($absolute);

        if ($raw === false) {
            return null;
        }

        return self::ofContent($raw, $absolute);
    }

    public static function ofContent(string $content, string $pathForType): string
    {
        $lower = strtolower($pathForType);

        if (str_ends_with($lower, '.blade.php')) {
            return self::hashBladeContent($content);
        }

        if (str_ends_with($lower, '.php')) {
            return self::hashPhpContent($content);
        }

        foreach (['.vue', '.tsx', '.jsx', '.svelte', '.ts', '.js', '.mjs', '.cjs', '.mts'] as $extension) {
            if (str_ends_with($lower, $extension)) {
                return self::hashJsContent($content);
            }
        }

        return hash('xxh128', $content);
    }

    private static function hashPhpContent(string $raw): string
    {
        $tokens = @token_get_all($raw);

        if ($tokens === []) {
            return hash('xxh128', $raw);
        }

        $normalised = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $normalised .= $token[1];
            } else {
                $normalised .= $token;
            }
        }

        return hash('xxh128', $normalised);
    }

    private static function hashBladeContent(string $raw): string
    {
        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $raw) ?? $raw;
        $stripped = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;

        return hash('xxh128', trim($stripped));
    }

    private static function hashJsContent(string $raw): string
    {
        $stripped = preg_replace('/^\s*\/\/[^\n]*$/m', '', $raw) ?? $raw;
        $stripped = preg_replace('/^\s*\/\*.*?\*\/\s*$/sm', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;

        return hash('xxh128', trim($stripped));
    }
}
