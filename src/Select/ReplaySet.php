<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Support\Paths;

/**
 * Which tests a replay pass would serve from cache instead of executing, decided the one
 * way the pipeline decides it: every result the graph holds for the branch whose test file
 * is **not** in the {@see RunList}.
 *
 * That single condition is the whole rule, because {@see RunListBuilder} has already
 * folded every other reason a test must execute into the list itself — the rule chain's
 * affected selection, test files the graph has never seen (`unknown`), files holding a
 * result that must be re-run (`rerun`, SPEC.md §6.2), and files holding a quarantined or
 * `#[NotCacheable]`/`never_cache` test. A test whose file survives all of that is exactly
 * a test `PHPUnit\ReplayState::decideFresh()` reaches its cached-result branch for.
 *
 * Extracted so the two places that report the figure cannot drift apart:
 * `Console\Runner\RunPipeline::replayedAgainst()` (what `run` and `run --dry-run` report as
 * replayed) and `Console\Runner\RunPipeline::verify()` (`verify`'s `would replay`,
 * SPEC.md §12.2). Before the extraction `verify` answered the question with a second,
 * independent implementation — it compared each stored content key against a freshly
 * re-observed one — and re-recorded edges on the same pass, so any test that gained an
 * edge got a new key and was dropped from the count. The figure therefore drifted with how
 * many passes had run rather than describing the tree (measured on a real 9056-test suite:
 * `9056 − would replay` fell 2001 → 1941 → 1502 → 1253 across four identical passes),
 * while `run` on the same unchanged tree replayed all 9056 — because `run` never
 * re-observes anything, it reads what this class reads.
 *
 * One deliberate imprecision, shared with `run`'s own reported figure so the two agree: a
 * `#[Depends]` provider is counted here, but {@see \Manuglopez\Replay\PHPUnit\ReplayState::isDependsProvider()}
 * makes the in-process extension execute it anyway (a dependent would otherwise receive
 * `null`). Answering that needs the test class loaded and PHPUnit's metadata parser, which
 * neither this class nor `run`'s summary has; agreeing with `run` matters more here than
 * being right about a handful of providers, and the divergence figure beside it is
 * unaffected either way.
 */
final readonly class ReplaySet
{
    /** @param array<string, float> $times test id => the cached duration replaying it saves */
    private function __construct(private array $times)
    {
    }

    /**
     * @param list<string> $runList project-relative test files this pass must execute
     *        ({@see RunList::files()}, after any remote-replay removals)
     */
    public static function against(Graph $graph, string $branch, array $runList): self
    {
        $inRunList = array_fill_keys($runList, true);
        $root = $graph->projectRoot();
        $onDisk = [];
        $times = [];

        foreach ($graph->results($branch) as $testId => $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || $file === '' || isset($inRunList[$file])) {
                continue;
            }

            // A test file gone from disk is in no RunListBuilder bucket — `unknown` and
            // `notCacheable` come from a directory walk, `rerun` skips a missing file
            // explicitly, and `Selector::dropMissingTestFiles()` drops it from the selection —
            // so it is absent from the run list for the one reason that does NOT mean
            // "replayable", and its stale cached results would otherwise be counted. `run`
            // normally hides that by pruning first (deleting a test file is itself a change,
            // so `pruneResultsForMissingFiles()` runs), but this class must not depend on the
            // caller having pruned: `verify` deliberately does not prune (a measurement may
            // not delete state, see `RunPipeline::replaySetBeforeVerify()`), and the point of
            // this class is to be an answer no caller can make disagree. Once per file, not
            // once per test.
            $onDisk[$file] ??= is_file(Paths::join($root, $file));

            if ($onDisk[$file]) {
                $times[$testId] = $result['time'];
            }
        }

        return new self($times);
    }

    /**
     * Nothing would be replayed: the pass has no usable baseline to replay from, so
     * `run` would record instead ({@see \Manuglopez\Replay\Console\Runner\RunPipeline::runRecord()}).
     */
    public static function none(): self
    {
        return new self([]);
    }

    public function has(string $testId): bool
    {
        return isset($this->times[$testId]);
    }

    public function count(): int
    {
        return count($this->times);
    }

    /** @return list<string> */
    public function testIds(): array
    {
        return array_keys($this->times);
    }

    /** Wall-clock seconds the cached results in this set were originally recorded taking. */
    public function savedSeconds(): float
    {
        $saved = 0.0;

        foreach ($this->times as $time) {
            $saved += $time;
        }

        return $saved;
    }
}
