<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

/**
 * Accumulates the never-replay-from-cache entries observed while PHPUnit prepares each
 * test, from two independent subscribers that both add to the same instance:
 *  - `#[NotCacheable]` (SPEC.md §8 rule 1), via {@see \Manuglopez\Replay\PHPUnit\Subscribers\RecordNotCacheableOnPreparationStarted}:
 *    a project-relative test file path for a class-level attribute, or a `Class::method`
 *    id for a method-level one.
 *  - `#[Repeat]`/`#[Retry]` (PHPUnit >= 13.3 only), via {@see \Manuglopez\Replay\PHPUnit\Subscribers\RecordRepeatOrRetryNotCacheableOnPreparationStarted}:
 *    the exact, possibly repetition/attempt-suffixed `PHPUnit\Event\Code\TestMethod::id()`
 *    of the test currently being prepared — never the bare `Class::method` id, which for
 *    a decorated method would not match what `Hermeticity\Policy::cacheable()` looks up.
 * The subscriber that populates a file-path entry already relativises it against the
 * project root, so this class only needs to deduplicate. Written out as
 * `not_cacheable.json` by {@see RunWriter::flush()}, and folded into the in-memory
 * partial by {@see \Manuglopez\Replay\PHPUnit\ReplayState::persistInProcess()}.
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
