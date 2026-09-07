<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

/**
 * What a pass has to execute, and why: the affected selection produced by the rule
 * chain plus the three synthetic buckets (test files the graph has never seen, files
 * holding a result that must be re-run, files holding a non-cacheable test).
 * docs/INTERNALS.md "Shared services extracted from RunPipeline".
 */
final class RunList
{
    /** @var array<int, string> PHPUnit\Framework\TestStatus\TestStatus::asInt() names, SPEC.md §4.2 */
    private const STATUS_NAMES = [
        0 => 'success',
        1 => 'skipped',
        2 => 'incomplete',
        3 => 'notice',
        4 => 'deprecation',
        5 => 'risky',
        6 => 'warning',
        7 => 'failure',
        8 => 'error',
    ];

    /** @var list<string>|null */
    private ?array $files = null;

    /** @var array<string, true>|null */
    private ?array $index = null;

    /** @var array<string, true>|null */
    private ?array $uncachedIndex = null;

    /**
     * @param list<string> $unknown test files on disk the graph does not know
     * @param list<string> $rerun test files with at least one result that must be re-run
     * @param list<string> $quarantined test files with at least one test automatically
     *   quarantined by {@see \Manuglopez\Replay\Hermeticity\Quarantine} (a flip)
     * @param array<string, int> $rerunStatuses test file => the status that forced the re-run
     * @param array<string, Reason> $quarantineReasons test file => why it is quarantined
     * @param list<string> $notCacheable test files with at least one test made non-cacheable
     *   by a `#[NotCacheable]` attribute or a `never_cache` glob — distinct from automatic
     *   quarantine (docs/INTERNALS.md "Hermeticity", SPEC.md §8)
     * @param array<string, Reason> $notCacheableReasons test file => why it is not cacheable
     */
    public function __construct(
        public readonly Selection $selection,
        public readonly array $unknown,
        public readonly array $rerun,
        public readonly array $quarantined = [],
        private readonly array $rerunStatuses = [],
        private readonly array $quarantineReasons = [],
        public readonly array $notCacheable = [],
        private readonly array $notCacheableReasons = [],
    ) {
    }

    /** @return list<string> sorted union of every bucket */
    public function files(): array
    {
        if ($this->files !== null) {
            return $this->files;
        }

        $files = array_values(array_unique([
            ...$this->selection->testFiles(),
            ...$this->unknown,
            ...$this->rerun,
            ...$this->quarantined,
            ...$this->notCacheable,
        ]));

        sort($files);

        return $this->files = $files;
    }

    public function has(string $testFileRel): bool
    {
        $this->index ??= array_fill_keys($this->files(), true);

        return isset($this->index[$testFileRel]);
    }

    /**
     * Every reason this file is in the run list: the rule-chain reasons first, then the
     * synthetic ones (`Uncached`, `Rerun`, `Quarantine`, `NotCacheable`).
     *
     * @return list<Reason>
     */
    public function reasonsFor(string $testFileRel): array
    {
        $reasons = $this->selection->reasons()[$testFileRel] ?? [];

        if (in_array($testFileRel, $this->unknown, true)) {
            $reasons[] = new Reason('Uncached', 'new test file');
        }

        if (in_array($testFileRel, $this->rerun, true)) {
            $reasons[] = new Reason('Rerun', self::statusName($this->rerunStatuses[$testFileRel] ?? -1));
        }

        if (in_array($testFileRel, $this->quarantined, true)) {
            $reasons[] = $this->quarantineReasons[$testFileRel] ?? new Reason('Quarantine', 'not cacheable');
        }

        if (in_array($testFileRel, $this->notCacheable, true)) {
            $reasons[] = $this->notCacheableReasons[$testFileRel] ?? new Reason('NotCacheable', 'not cacheable');
        }

        return $reasons;
    }

    /** `'unknown'` for anything outside `TestStatus::asInt()`. */
    public static function statusName(int $status): string
    {
        return self::STATUS_NAMES[$status] ?? 'unknown';
    }

    /**
     * The single bucket an executed test file's tests are counted under for
     * Report\Summary (docs/INTERNALS.md "Summary counters", SPEC.md §11): the rule-chain
     * selection first (`'affected'`), then the synthetic unknown/rerun buckets
     * (`'uncached'`), then quarantine (`'quarantined'`) — the same precedence
     * {@see self::reasonsFor()} lists reasons in. Every file actually in {@see self::files()}
     * matches at least one of the three, so this always returns one of them.
     *
     * TODO(wiring): `$notCacheable` files (distinct from `$quarantined` since the split in
     * docs/INTERNALS.md "Hermeticity") still fall through to the `'quarantined'` default
     * below, matching current behaviour (`Report\Summary` has no `notCacheable` segment of
     * its own yet). Classify `notCacheable` in `Console\Runner\RunPipeline::classifyExecuted()`
     * once the wrapper's Summary construction is updated to pass it through.
     */
    public function primaryReasonFor(string $testFileRel): string
    {
        if ($this->selection->has($testFileRel)) {
            return 'affected';
        }

        $this->uncachedIndex ??= array_fill_keys([...$this->unknown, ...$this->rerun], true);

        if (isset($this->uncachedIndex[$testFileRel])) {
            return 'uncached';
        }

        return 'quarantined';
    }
}
