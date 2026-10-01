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
     * @param list<string> $noResult the subset of {@see self::$unknown} the graph knows but holds no
     *   result for (only the reason text differs from a brand-new test file). Never a file of
     *   `$stale`: a file whose results {@see LayerAudit} stopped serving is explained by that,
     *   not by the bare absence it leaves behind
     * @param list<string> $stale test files whose cached results no valid layer can serve
     *   any more ({@see LayerAudit}): the layer holding them was recorded on a tree the
     *   current one has moved away from, and nothing underneath covers every test id
     * @param array<string, Reason> $staleReasons test file => why a layer stopped serving it,
     *   for every audited file (also those served from a layer underneath instead)
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
        private readonly array $noResult = [],
        public readonly array $stale = [],
        private readonly array $staleReasons = [],
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
            ...$this->stale,
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
     * synthetic ones (`StaleLayer`, `Uncached`, `Rerun`, `Quarantine`, `NotCacheable`).
     * `StaleLayer` leads the synthetic ones because it is the cause of whichever follows: a
     * file whose own cached pass was not valid on this tree and whose layer underneath
     * holds a failure is re-run for that failure, and would have been served without it.
     *
     * @return list<Reason>
     */
    public function reasonsFor(string $testFileRel): array
    {
        $reasons = $this->selection->reasons()[$testFileRel] ?? [];

        if (isset($this->staleReasons[$testFileRel])) {
            $reasons[] = $this->staleReasons[$testFileRel];
        }

        if (in_array($testFileRel, $this->unknown, true)) {
            $reasons[] = new Reason('Uncached', in_array($testFileRel, $this->noResult, true) ? 'no cached result' : 'new test file');
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
     * A copy without these files in the selection or the stale bucket: the files a remote
     * object stands in for (`PHPUnit\ReplayState`, `Cache\Remote\Exchange::served()`), which
     * then decide from the results merged in for them. Their stale reason is kept, so `--explain`
     * still says why the local result was not used.
     *
     * @param list<string> $files
     */
    public function withoutServed(array $files): self
    {
        if ($files === []) {
            return $this;
        }

        $drop = array_fill_keys($files, true);
        $selection = new Selection($this->selection->sourcePhpChanged);

        foreach ($this->selection->reasons() as $file => $reasons) {
            if (isset($drop[$file])) {
                continue;
            }

            foreach ($reasons as $reason) {
                $selection->add($file, $reason);
            }
        }

        return new self(
            $selection,
            $this->unknown,
            $this->rerun,
            $this->quarantined,
            $this->rerunStatuses,
            $this->quarantineReasons,
            $this->notCacheable,
            $this->notCacheableReasons,
            $this->noResult,
            array_values(array_diff($this->stale, $files)),
            $this->staleReasons,
        );
    }

    /**
     * The single bucket an executed test file's tests are counted under for
     * Report\Summary (docs/INTERNALS.md "Summary counters", SPEC.md §11): the rule-chain
     * selection first (`'affected'`), then the synthetic unknown/rerun/stale buckets
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

        // A stale file has no valid cached result to replay: uncached, as far as the
        // summary is concerned.
        $this->uncachedIndex ??= array_fill_keys([...$this->unknown, ...$this->rerun, ...$this->stale], true);

        if (isset($this->uncachedIndex[$testFileRel])) {
            return 'uncached';
        }

        return 'quarantined';
    }
}
