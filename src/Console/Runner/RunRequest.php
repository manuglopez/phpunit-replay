<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

/**
 * Everything a Console command gathers from the CLI before handing off to
 * {@see RunPipeline}. docs/INTERNALS.md "Wrapper pipeline — detailed algorithm".
 */
final readonly class RunRequest
{
    /**
     * @param list<string> $phpunitArgs everything meant for `vendor/bin/phpunit` itself
     * @param ?int $parallel Paratest (SPEC.md §13): null = sequential PHPUnit (default),
     *     0 = `--parallel`/`-p` with no number (Paratest's own "auto" process count),
     *     a positive int = that many Paratest processes.
     */
    public function __construct(
        public string $cwd,
        public array $phpunitArgs,
        public bool $fresh,
        public bool $noRemote,
        public bool $explain,
        public bool $dryRun,
        public ?string $logJunit,
        public bool $allowCiBaseline,
        public bool $record,
        public ?int $parallel = null,
    ) {
    }

    /**
     * Translates the raw `--parallel`/`-p` option value (`InputOption::VALUE_OPTIONAL`,
     * declared with a `false` default in `RunCommand`/`RecordCommand`) into {@see self::$parallel}:
     * `false` is Symfony's own sentinel for "the option was never given" (its `addOption()`
     * default, left untouched by parsing) → null (sequential); `null` is what parsing produces
     * for the bare `--parallel`/`-p` form (no value token) → 0 (Paratest's auto process count);
     * anything else is the requested process count.
     */
    public static function parseParallel(mixed $optionValue): ?int
    {
        if ($optionValue === false) {
            return null;
        }

        if ($optionValue === null || ! is_numeric($optionValue)) {
            return 0;
        }

        return max(0, (int) $optionValue);
    }
}
