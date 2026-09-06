<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

/**
 * Everything a pass needs to know about *where* it is running: the project, the state
 * directory, the git branch/sha it can attribute a baseline to, and whether it is
 * allowed to publish one. Shared by the wrapper pipeline and the in-process extension
 * (docs/INTERNALS.md "Shared services extracted from RunPipeline").
 */
final readonly class RunContext
{
    public function __construct(
        public string $root,
        public string $stateDir,
        public string $branch,
        public ?string $head,
        public string $defaultBranch,
        /** false on a detached HEAD: there is no branch to attribute the baseline to */
        public bool $persist,
        public bool $ciMode,
        public bool $allowCiBaseline,
    ) {
    }

    /** `CI` set to anything other than an empty string, `0` or `false` (SPEC.md §12). */
    public static function ciDetected(): bool
    {
        $value = getenv('CI');

        if (! is_string($value) || $value === '') {
            return false;
        }

        return ! in_array(strtolower($value), ['0', 'false'], true);
    }
}
