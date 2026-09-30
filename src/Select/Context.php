<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Cache\Graph;

/**
 * Mutable state threaded through the Select\Rule chain: the changes still unaccounted for
 * (`$remaining`) and the Selection each rule contributes to.
 */
final class Context
{
    /** @var list<string> */
    public array $remaining;

    /**
     * Every changed path the chain was given, consumed or not: what an additive rule
     * (`Rules\WatchRule` for configured patterns, `Laravel\Rules\BladeRule`) reads, because
     * another rule having claimed a file says nothing about who else depends on it.
     *
     * @var list<string>
     */
    public readonly array $changed;

    /** @param list<string> $remaining project-relative changed paths not yet consumed */
    public function __construct(
        public readonly Graph $graph,
        public readonly string $projectRoot,
        public readonly TestPaths $testPaths,
        public readonly WatchPatterns $watch,
        array $remaining,
        public readonly Selection $selection,
    ) {
        $this->remaining = $remaining;
        $this->changed = $remaining;
    }

    /** Removes every occurrence of $rel from the remaining changes. */
    public function consume(string $rel): void
    {
        $this->remaining = array_values(array_filter(
            $this->remaining,
            static fn (string $candidate): bool => $candidate !== $rel,
        ));
    }
}
