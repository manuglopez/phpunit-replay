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
    ) {
    }
}
