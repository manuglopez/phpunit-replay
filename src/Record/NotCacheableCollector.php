<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

/**
 * Accumulates the `#[NotCacheable]` entries observed while PHPUnit prepares each test
 * (SPEC.md §8 rule 1): project-relative test file paths for a class-level attribute, and
 * `Class::method` ids for a method-level one. The subscriber that populates this already
 * relativises file paths against the project root, so this class only needs to
 * deduplicate. Written out as `not_cacheable.json` by {@see RunWriter::flush()}, and
 * folded into the in-memory partial by {@see \Manuglopez\Replay\PHPUnit\ReplayState::persistInProcess()}.
 */
final class NotCacheableCollector
{
    /** @var array<string, true> */
    private array $entries = [];

    public function add(string $entry): void
    {
        if ($entry !== '') {
            $this->entries[$entry] = true;
        }
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->entries);
    }

    public function reset(): void
    {
        $this->entries = [];
    }
}
