<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

/**
 * Finds and rewrites a `--coverage-php=FILE` (or `--coverage-php FILE`) option among the
 * PHPUnit args the user passed the wrapper (SPEC.md §3.2 last paragraph, docs/INTERNALS.md
 * "CoverageMerger"): {@see RunPipeline} redirects PHPUnit's own output to a run-scoped path
 * so it can fold snapshots of replayed test files into the file the user actually asked for
 * before that path ever becomes visible to PHPUnit itself.
 */
final class CoveragePhpOption
{
    private const OPTION = '--coverage-php';

    /** @param list<string> $phpunitArgs */
    public static function path(array $phpunitArgs): ?string
    {
        $count = count($phpunitArgs);

        for ($i = 0; $i < $count; $i++) {
            $arg = $phpunitArgs[$i];

            if ($arg === self::OPTION) {
                $value = $phpunitArgs[$i + 1] ?? null;

                return $value !== null && $value !== '' ? $value : null;
            }

            if (str_starts_with($arg, self::OPTION . '=')) {
                $value = substr($arg, strlen(self::OPTION) + 1);

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * @param list<string> $phpunitArgs
     * @return list<string>
     */
    public static function withPath(array $phpunitArgs, string $replacement): array
    {
        $out = [];
        $count = count($phpunitArgs);
        $skipNext = false;

        for ($i = 0; $i < $count; $i++) {
            $arg = $phpunitArgs[$i];

            if ($skipNext) {
                $out[] = $replacement;
                $skipNext = false;

                continue;
            }

            if ($arg === self::OPTION) {
                $out[] = $arg;
                $skipNext = true;

                continue;
            }

            if (str_starts_with($arg, self::OPTION . '=')) {
                $out[] = self::OPTION . '=' . $replacement;

                continue;
            }

            $out[] = $arg;
        }

        return $out;
    }
}
