<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Support;

/**
 * Minimal glob matcher, shared by {@see \Manuglopez\Replay\Select\WatchPatterns} and the
 * hermeticity `never_cache` globs (SPEC.md §8 rule 2): `*` matches within one path
 * segment, `**` matches any depth (including none), `?` matches one non-separator
 * character, everything else is matched literally.
 */
final class Glob
{
    public static function matches(string $pattern, string $path): bool
    {
        $pattern = str_replace('\\', '/', $pattern);
        $path = str_replace('\\', '/', $path);

        $regex = '';
        $len = strlen($pattern);
        $i = 0;

        while ($i < $len) {
            $c = $pattern[$i];

            if ($c === '*' && isset($pattern[$i + 1]) && $pattern[$i + 1] === '*') {
                $regex .= '.*';
                $i += 2;

                if (isset($pattern[$i]) && $pattern[$i] === '/') {
                    $i++;
                }
            } elseif ($c === '*') {
                $regex .= '[^/]*';
                $i++;
            } elseif ($c === '?') {
                $regex .= '[^/]';
                $i++;
            } else {
                $regex .= preg_quote($c, '#');
                $i++;
            }
        }

        return (bool) preg_match('#^' . $regex . '$#', $path);
    }
}
