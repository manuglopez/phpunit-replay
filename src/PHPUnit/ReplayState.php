<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use LogicException;
use Manuglopez\Replay\Cache\BaselineWriter;
use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Cache\RunContext;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Change\LastRunTree;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Laravel\LaravelDetector;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\PHPUnit\Decision\Decision;
use Manuglopez\Replay\PHPUnit\Decision\ReplayIncomplete;
use Manuglopez\Replay\PHPUnit\Decision\ReplayPass;
use Manuglopez\Replay\PHPUnit\Decision\ReplaySkipped;
use Manuglopez\Replay\PHPUnit\Decision\Run;
use Manuglopez\Replay\Record\CoverageDriver;
use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\NotCacheableCollector;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\ResultCollector;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Record\RunWriter;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Report\Summary;
use Manuglopez\Replay\Select\RunList;
use Manuglopez\Replay\Select\RunListBuilder;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Support\Paths;
use PHPUnit\Metadata\DependsOnMethod;
use PHPUnit\Metadata\Parser\Registry as MetadataRegistry;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Static holder shared between `ReplayExtension` and the `Replayable` trait — a trait
 * mixed into the user's `TestCase` has no constructor injection, so a process-wide
 * singleton is the only way to reach the same `Recorder`/`ResultCollector` instances
 * the extension registered subscribers for.
 *
 * Two ways in:
 *  - {@see self::boot()}: the wrapper already decided everything (phase 1, filtered mode).
 *  - {@see self::bootInProcess()}: no wrapper, so this class resolves the project, the
 *    baseline and the run list itself and decides the mode (SPEC.md §6.1).
 *
 * @phpstan-import-type TestResultArray from Graph
 */
final class ReplayState
{
    private static ?Mode $mode = null;

    private static ?string $root = null;

    private static ?string $stateDir = null;

    private static ?string $runId = null;

    private static ?Recorder $recorder = null;

    private static ?ResultCollector $collector = null;

    private static ?NotCacheableCollector $notCacheable = null;

    private static ?RunWriter $runWriter = null;

    private static ?float $startedAt = null;

    // --- in-process replay (SPEC.md §6.2, docs/INTERNALS.md "In-process replay") ---

    private static bool $inProcess = false;

    private static ?Graph $graph = null;

    private static ?RunList $runList = null;

    private static ?Policy $policy = null;

    private static ?ConfigurationReader $reader = null;

    private static ?Quarantine $quarantine = null;

    private static ?Git $git = null;

    private static string $branch = 'HEAD';

    private static ?string $head = null;

    private static string $defaultBranch = 'main';

    private static bool $persist = false;

    private static ?Config $config = null;

    private static ?ObjectStore $objects = null;

    private static bool $remoteOpen = false;

    /** @var array<string, true> test ids whose cached result was merged in from a remote object, for the `replayedRemote` counter */
    private static array $remoteTestIds = [];

    /** @var array<string, Decision> */
    private static array $decisions = [];

    /** @var array<string, TestResultArray> */
    private static array $replayed = [];

    /** @var array{affected: int, uncached: int, replayed: int, quarantined: int, notCacheable: int, replayedRemote: int} */
    private static array $counters = [
        'affected' => 0, 'uncached' => 0, 'replayed' => 0, 'quarantined' => 0, 'notCacheable' => 0, 'replayedRemote' => 0,
    ];

    private static float $savedSeconds = 0.0;

    /** @var array<string, true> `Class::method` targets of a `#[Depends]` seen so far */
    private static array $dependedUpon = [];

    /** @var array<string, true> classes whose method metadata has already been scanned */
    private static array $scannedForDepends = [];

    public static function boot(Mode $mode, string $root, string $stateDir, string $runId, ?CoverageDriver $driver): void
    {
        self::$mode = $mode;
        self::$root = $root;
        self::$stateDir = $stateDir;
        self::$runId = $runId;
        self::$recorder = $driver !== null ? new Recorder($driver) : null;
        self::$collector = new ResultCollector();
        self::$notCacheable = new NotCacheableCollector();
        self::$runWriter = new RunWriter($stateDir . '/runs/' . $runId, $root);
        self::$startedAt = microtime(true);
    }

    /**
     * Boots the extension without a wrapper: resolves the project root, the state
     * directory, the baseline and the run list, then settles on a mode
     * (docs/INTERNALS.md "Mode decision without wrapper env"). `Mode::Off` means replay
     * cannot work here and the caller registers nothing.
     */
    public static function bootInProcess(Config $config, Configuration $configuration): Mode
    {
        $root = self::resolveRoot($configuration);
        $git = new Git($root);

        if (! Git::available() || ! $git->isRepository() || ! $git->hasCommits()) {
            self::warn('git repository with at least one commit required: in-process replay disabled');

            return Mode::Off;
        }

        $topLevel = $git->topLevel();

        if ($topLevel !== null) {
            $root = $topLevel;
            $git = new Git($root);
        }

        $stateDir = StateDirectory::resolve($config->stateDir, $root);
        $reader = new ConfigurationReader($configuration);
        $driver = DriverDetector::detect(SourceScope::fromProjectRoot($root, $configuration));
        $fingerprint = Fingerprint::compute($root, $driver?->name() ?? 'none');

        $branch = $git->currentBranch();
        $persist = $branch !== null;
        $branch ??= 'HEAD';
        $defaultBranch = $config->defaultBranch ?? $git->defaultBranch() ?? 'main';

        // Remote cache (SPEC.md §9): config `remote` only — in-process mode has no
        // `--no-remote` flag/`PHPUNIT_REPLAY=0` equivalent to check. `end()` happens once,
        // from self::persistInProcess(), whatever mode this settles on.
        self::openRemote($config, $stateDir, $root);

        $store = new GraphStore($stateDir, $root);
        $localGraph = $store->load();
        $graph = $localGraph !== null
            ? self::reconcileGraph($localGraph, $fingerprint, $defaultBranch)
            : self::reconcileGraph(self::pullStartingGraph($config, $branch, $defaultBranch, $root, $store), $fingerprint, $defaultBranch);

        $mode = self::decideMode($config, $reader, $graph, $branch, $driver !== null);

        if ($mode === Mode::Off) {
            return Mode::Off;
        }

        if ($mode === Mode::Record) {
            $graph = new Graph($root);
            $graph->setFingerprint($fingerprint);
            $graph->setDefaultBranch($defaultBranch);
        }

        self::boot($mode, $root, $stateDir, self::newRunId(), $mode === Mode::ResultsOnly ? null : $driver);

        self::$inProcess = true;
        self::$graph = $graph;
        self::$reader = $reader;
        self::$git = $git;
        self::$branch = $branch;
        self::$head = $git->currentSha();
        self::$defaultBranch = $defaultBranch;
        self::$persist = $persist;
        self::$config = $config;
        self::$quarantine = Quarantine::load($stateDir);
        self::$quarantine->setReleaseAfter($config->quarantineReleaseAfter);

        if ($mode === Mode::Replay && $graph !== null) {
            self::prepareReplay($config, $configuration, $graph, $root, $stateDir, $branch, $git);
        }

        $settled = self::$mode ?? $mode;

        Warnings::debug(sprintf(
            'in-process: mode=%s driver=%s root=%s branch=%s baseline=%s runList=%d',
            $settled->value,
            $driver?->name() ?? 'none',
            $root,
            $branch,
            substr($graph?->recordedSha($branch) ?? 'none', 0, 7),
            self::$runList !== null ? count(self::$runList->files()) : 0,
        ));

        return $settled;
    }

    /**
     * Test seam: boots the in-process state with collaborators built by the caller, so
     * `decide()` can be exercised without a git repository or a real PHPUnit run.
     */
    public static function bootForTests(
        Mode $mode,
        string $root,
        string $stateDir,
        Graph $graph,
        RunList $runList,
        Policy $policy,
        ConfigurationReader $reader,
        string $branch = 'main',
    ): void {
        self::boot($mode, $root, $stateDir, 'test-run', null);

        self::$inProcess = true;
        self::$graph = $graph;
        self::$runList = $runList;
        self::$policy = $policy;
        self::$reader = $reader;
        self::$branch = $branch;
        self::$quarantine = Quarantine::load($stateDir);
    }

    public static function isBooted(): bool
    {
        return self::$mode !== null;
    }

    /** True when this process drives replay itself, without the wrapper. */
    public static function isInProcess(): bool
    {
        return self::$inProcess;
    }

    public static function mode(): Mode
    {
        return self::$mode ?? throw self::notBooted();
    }

    public static function root(): string
    {
        return self::$root ?? throw self::notBooted();
    }

    public static function stateDir(): string
    {
        return self::$stateDir ?? throw self::notBooted();
    }

    public static function runId(): string
    {
        return self::$runId ?? throw self::notBooted();
    }

    /** Null when the mode did not have a coverage driver to record edges with. */
    public static function recorder(): ?Recorder
    {
        self::assertBooted();

        return self::$recorder;
    }

    public static function collector(): ResultCollector
    {
        return self::$collector ?? throw self::notBooted();
    }

    public static function notCacheableCollector(): NotCacheableCollector
    {
        return self::$notCacheable ?? throw self::notBooted();
    }

    public static function runWriter(): RunWriter
    {
        return self::$runWriter ?? throw self::notBooted();
    }

    public static function startedAt(): float
    {
        return self::$startedAt ?? throw self::notBooted();
    }

    public static function graph(): ?Graph
    {
        return self::$graph;
    }

    public static function runList(): ?RunList
    {
        return self::$runList;
    }

    /**
     * What to do with one test (SPEC.md §6.2). Memoised per test id: the recorder
     * subscriber and the `Replayable` trait both ask, and both must get the same answer.
     */
    public static function decide(string $testFileAbsolute, string $testId): Decision
    {
        if (isset(self::$decisions[$testId])) {
            return self::$decisions[$testId];
        }

        $decision = self::decideFresh($testFileAbsolute, $testId);

        if ($decision instanceof Run) {
            match ($decision->reason) {
                'affected' => self::$counters['affected']++,
                'quarantined' => self::$counters['quarantined']++,
                'not-cacheable' => self::$counters['notCacheable']++,
                default => self::$counters['uncached']++,
            };
        }

        Warnings::debug(sprintf(
            'decide %s -> %s',
            $testId,
            $decision instanceof Run ? 'run (' . $decision->reason . ')' : 'replay (' . self::replayKind($decision) . ')',
        ));

        return self::$decisions[$testId] = $decision;
    }

    /** Remembers the cached result a replayed test must be persisted with (SPEC.md §6.3). */
    public static function markReplayed(string $testId, Decision $decision): void
    {
        $cached = match (true) {
            $decision instanceof ReplayPass => $decision->cached,
            $decision instanceof ReplaySkipped => $decision->cached,
            $decision instanceof ReplayIncomplete => $decision->cached,
            default => null,
        };

        if ($cached === null || isset(self::$replayed[$testId])) {
            return;
        }

        self::$replayed[$testId] = $cached;
        self::$counters['replayed']++;
        self::$savedSeconds += $cached['time'];

        if (isset(self::$remoteTestIds[$testId])) {
            self::$counters['replayedRemote']++;
        }
    }

    /**
     * `executed` is derived rather than counted: every test PHPUnit actually reported,
     * minus the ones the trait satisfied from cache — so tests that do not use the trait
     * (and therefore always run for real) are counted correctly.
     *
     * Every field here counts individual tests, not test files: `decide()` increments
     * `affected`/`uncached`/`quarantined`/`notCacheable` once per `Run` decision (one per
     * test id) and `markReplayed()` increments `replayed` once per replayed test id, so
     * `executed === affected + uncached + quarantined + notCacheable` holds the same way
     * it does for the wrapper's own Summary (docs/INTERNALS.md "Summary counters",
     * Console\Runner\RunPipeline::classifyExecuted()) — this is the one path that
     * already got it right, the wrapper had to be brought in line with it. `quarantined`
     * and `notCacheable` are counted separately here (a `Run` decision's reason is either
     * `'quarantined'` or `'not-cacheable'`, never both), unlike the wrapper's own
     * `RunList::primaryReasonFor()`, which still folds `notCacheable` files into
     * `'quarantined'` until `RunPipeline::classifyExecuted()` is wired to split them too.
     *
     * @return array{affected: int, uncached: int, replayed: int, quarantined: int, notCacheable: int, replayedRemote: int, executed: int}
     */
    public static function counters(): array
    {
        $total = self::$collector !== null ? count(self::$collector->all()) : 0;

        return [
            ...self::$counters,
            'executed' => max(0, $total - self::$counters['replayed']),
        ];
    }

    /**
     * Whether another test in `$className` declares `#[Depends]` on `$methodName`. Such
     * a test is never replayed: the dependent would receive `null` instead of the
     * provider's return value (see docs/spikes/in-process-replay.md).
     */
    public static function isDependsProvider(string $className, string $methodName): bool
    {
        self::scanDepends($className);

        return isset(self::$dependedUpon[$className . '::' . $methodName]);
    }

    /**
     * At `TestRunner\ExecutionFinished`: fold this run into the graph exactly the way
     * the wrapper folds a run partial, with replayed tests contributing their ORIGINAL
     * cached status/time/assertions/message (SPEC.md §6.3).
     */
    public static function persistInProcess(): void
    {
        $graph = self::$graph;
        $mode = self::$mode;

        if (! self::$inProcess || $graph === null || $mode === null || $mode === Mode::Off) {
            self::closeRemote();

            return;
        }

        $root = self::root();
        $recorder = self::$recorder;
        $recordsEdges = $recorder !== null && $mode !== Mode::ResultsOnly;

        $truncated = self::$runWriter?->isTruncated() ?? false;
        $complete = ! $truncated && $mode !== Mode::ResultsOnly;

        $partial = new RunPartial(
            $recordsEdges && $recorder !== null ? self::relativiseMap($recorder->perTestFiles(), $root, true) : [],
            self::resultsForPersist($root),
            $recordsEdges && $recorder !== null ? self::relativiseMap($recorder->perTestTables(), $root, false) : [],
            ['truncated' => $truncated],
            notCacheable: self::$notCacheable?->all() ?? [],
        );

        // Laravel integration (SPEC.md §10): widens database test tables the same way the
        // wrapper does (Console\Runner\RunPipeline) before folding the partial into the graph.
        if (LaravelDetector::enabled($root, self::$config ?? Config::defaults())) {
            $partial = LaravelIntegration::augment($partial, $root);
        }

        $updater = new GraphUpdater($graph, $root, new ContentKey($root), self::$quarantine);
        $applied = $updater->apply($partial, self::$branch, recordsEdges: $recordsEdges, complete: $complete);

        $git = self::$git ?? new Git($root);

        $context = new RunContext(
            $root,
            self::stateDir(),
            self::$branch,
            self::$head,
            self::$defaultBranch,
            self::$persist,
            RunContext::ciDetected(),
            false,
        );

        (new BaselineWriter(new GraphStore(self::stateDir(), $root), $git, new ChangedFiles($root, $git)))
            ->commit($graph, $updater, $context, $complete);

        if (self::$quarantine !== null && self::$quarantine->all() !== []) {
            self::$quarantine->save(self::stateDir());
        }

        self::pushAfterRun($graph, $applied['touched'], $complete);
        self::closeRemote();
    }

    /**
     * docs/INTERNALS.md "Pipeline changes", mirrored from
     * `Console\Runner\RunPipeline::pushAfterRun()`: publish one `objects/<shard>/<k>.json`
     * per test file this pass executed, and the whole graph when `remote_push` is `all` and
     * the pass earned a complete, persistable baseline. `remote_push => 'off'` makes the
     * remote pull-only; `CI=true` publishes objects but never the graph (in-process mode has
     * no `--allow-ci-baseline` override to check, unlike the wrapper).
     *
     * @param list<string> $executedTestFiles project-relative, from GraphUpdater::apply()['touched']
     */
    private static function pushAfterRun(Graph $graph, array $executedTestFiles, bool $complete): void
    {
        $objects = self::$objects;
        $config = self::$config;

        if ($objects === null || $config === null || $config->remotePush === 'off') {
            return;
        }

        $branch = self::$branch;
        $contentKey = new ContentKey(self::root());
        $own = $graph->ownResults($branch);
        $pushed = 0;

        foreach ($executedTestFiles as $file) {
            if ($graph->isNotCacheable($file)) {
                continue;
            }

            $key = $contentKey->forTestFile($graph, $file);

            if ($key === null) {
                continue;
            }

            $results = [];

            foreach ($own as $testId => $result) {
                if (($result['file'] ?? null) === $file) {
                    $results[$testId] = $result;
                }
            }

            if ($results !== [] && $objects->putObject($key, $file, $results)) {
                $pushed++;
            }
        }

        Warnings::debug('remote: ' . $pushed . ' object(s) published');

        if ($config->remotePush !== 'all' || ! $complete || ! self::$persist) {
            return;
        }

        if (RunContext::ciDetected()) {
            Warnings::debug('remote: CI detected, the branch graph was not published (no --allow-ci-baseline override in-process)');

            return;
        }

        $body = $graph->encode();

        if ($body !== null) {
            $objects->putGraph($branch, $body);
        }
    }

    /** The line the summary subscriber prints; null when nothing ran under our control. */
    public static function summaryLine(): ?string
    {
        $mode = self::$mode;

        if (! self::$inProcess || $mode === null || $mode === Mode::Off) {
            return null;
        }

        $results = self::$collector !== null ? self::$collector->all() : [];

        if ($mode === Mode::Record) {
            return self::recordSummaryLine(count($results));
        }

        $success = true;

        foreach ($results as $result) {
            if ($result['status'] >= 7) {
                $success = false;

                break;
            }
        }

        $counters = self::counters();

        return (new Summary(
            $counters['executed'],
            $counters['affected'],
            $counters['uncached'],
            $counters['replayed'],
            $counters['replayedRemote'],
            $counters['quarantined'],
            self::$persist ? self::$branch : null,
            self::$persist ? self::$head : null,
            self::$savedSeconds,
            $success,
            $counters['notCacheable'],
        ))->format();
    }

    /** For tests: clears the singleton so each test starts from a clean slate. */
    public static function reset(): void
    {
        self::$mode = null;
        self::$root = null;
        self::$stateDir = null;
        self::$runId = null;
        self::$recorder = null;
        self::$collector = null;
        self::$notCacheable = null;
        self::$runWriter = null;
        self::$startedAt = null;

        self::$inProcess = false;
        self::$graph = null;
        self::$runList = null;
        self::$policy = null;
        self::$reader = null;
        self::$quarantine = null;
        self::$git = null;
        self::$branch = 'HEAD';
        self::$head = null;
        self::$defaultBranch = 'main';
        self::$persist = false;
        self::$config = null;
        self::$objects = null;
        self::$remoteOpen = false;
        self::$remoteTestIds = [];
        self::$decisions = [];
        self::$replayed = [];
        self::$counters = [
            'affected' => 0, 'uncached' => 0, 'replayed' => 0, 'quarantined' => 0, 'notCacheable' => 0, 'replayedRemote' => 0,
        ];
        self::$savedSeconds = 0.0;
        self::$dependedUpon = [];
        self::$scannedForDepends = [];
    }

    private static function decideFresh(string $testFileAbsolute, string $testId): Decision
    {
        $graph = self::$graph;
        $runList = self::$runList;
        $policy = self::$policy;
        $reader = self::$reader;

        if (! self::$inProcess || self::$mode !== Mode::Replay
            || $graph === null || $runList === null || $policy === null || $reader === null) {
            return new Run('no-baseline');
        }

        $rel = self::relativeTestFile($graph, $testFileAbsolute);

        if ($rel === null) {
            return new Run('uncached');
        }

        if ($runList->has($rel)) {
            return new Run('affected');
        }

        $method = self::methodOf($testId);

        if ($method === null) {
            return new Run('uncached');
        }

        if (! $graph->knowsTest($rel)) {
            return new Run('uncached');
        }

        if (self::isDependsProvider($method[0], $method[1])) {
            return new Run('depends-provider');
        }

        if (! $policy->cacheable($rel, $testId)) {
            return new Run($policy->reason($rel, $testId) === 'quarantine' ? 'quarantined' : 'not-cacheable');
        }

        $cached = $graph->result(self::$branch, $testId);

        if ($cached === null) {
            return new Run('uncached');
        }

        if ($reader->shouldRerun($cached['status'])) {
            return new Run('rerun');
        }

        return match ($cached['status']) {
            0, 3, 4, 5, 6 => new ReplayPass($cached['assertions'], $cached['status'] === 5, $cached),
            1 => new ReplaySkipped($cached['message'], $cached),
            2 => new ReplayIncomplete($cached['message'], $cached),
            default => new Run('rerun'),
        };
    }

    /** Steps 6-9 of the wrapper pipeline, in-process: what changed, and what must run. */
    private static function prepareReplay(
        Config $config,
        Configuration $configuration,
        Graph $graph,
        string $root,
        string $stateDir,
        string $branch,
        Git $git,
    ): void {
        $sha = $graph->recordedSha($branch);
        $changedFiles = new ChangedFiles($root, $git);
        $changed = $sha !== null ? $changedFiles->since($sha) : null;

        if ($changed === null) {
            // The baseline cannot be reached from HEAD: nothing may be replayed. With a
            // driver the whole suite is re-recorded; without one it can only contribute
            // results to the graph it already has.
            self::warn(sprintf(
                'baseline %s is not an ancestor of HEAD: recording a fresh baseline',
                substr($sha ?? 'unknown', 0, 7),
            ));

            if (self::$recorder !== null) {
                self::$mode = Mode::Record;
                self::$graph = self::freshGraph($root, $graph);
            } else {
                self::$mode = Mode::ResultsOnly;
            }

            return;
        }

        $lastRun = LastRunTree::load($stateDir);

        if ($lastRun !== null && $lastRun->branch === $branch) {
            $changed = $lastRun->filterUnchanged($changed, $changedFiles);
        }

        if ($changed !== []) {
            $graph->pruneMissingTestFiles();
            $graph->pruneResultsForMissingFiles($branch);
        }

        $testPaths = TestPaths::fromConfiguration($configuration, $root);

        $watch = new WatchPatterns();
        $watch->useDefaults($root, $testPaths->directories());

        if ($config->watch !== []) {
            $watch->add($config->watch);
        }

        $policy = new Policy($graph, $config, self::$quarantine ?? Quarantine::load($stateDir), $root);
        $reader = self::$reader ?? new ConfigurationReader($configuration);

        self::$policy = $policy;
        $runList = (new RunListBuilder(
            $graph,
            $testPaths,
            $watch,
            $reader,
            $policy,
            $root,
            LaravelIntegration::rulesFor($graph, $root, $config),
        ))->build($changed, $branch);
        self::$runList = self::replayAffectedFromRemote($graph, $runList, $branch, $root);

        Warnings::debug('changed: ' . ($changed === [] ? '(none)' : implode(', ', $changed)));
    }

    /**
     * SPEC.md §9 / docs/INTERNALS.md "Pipeline changes", mirrored from
     * `Console\Runner\RunPipeline::replayFromRemote()`: every test file the run list holds
     * *only* because the rule chain selected it (never unknown/rerun/quarantined/
     * not-cacheable, which must always execute regardless of the cache) gets its content
     * key recomputed from the graph's existing edges and looked up on the remote. A hit
     * merges the file's results into the graph under the current branch and drops the file
     * from the returned list's selection, so `self::decide()`'s normal cached-result path
     * replays each of its tests and `self::markReplayed()` counts them under
     * `replayedRemote` (`self::$remoteTestIds`).
     */
    private static function replayAffectedFromRemote(Graph $graph, RunList $runList, string $branch, string $root): RunList
    {
        $objects = self::$objects;

        if ($objects === null) {
            return $runList;
        }

        $contentKey = new ContentKey($root);
        $skip = array_fill_keys(
            [...$runList->unknown, ...$runList->rerun, ...$runList->quarantined, ...$runList->notCacheable],
            true,
        );
        $hit = [];

        foreach ($runList->selection->testFiles() as $file) {
            if (isset($skip[$file]) || $graph->isNotCacheable($file)) {
                continue;
            }

            $key = $contentKey->forTestFile($graph, $file);
            $object = $key === null ? null : $objects->object($key);

            if ($key === null || $object === null || self::holdsARerun($object['results'])) {
                continue;
            }

            foreach ($object['results'] as $testId => $result) {
                $result['file'] = $file;
                $result['key'] = $key;
                $graph->setResult($branch, $testId, $result);
                self::$remoteTestIds[$testId] = true;
            }

            $hit[] = $file;
            Warnings::debug('remote: replayed ' . $file . ' from objects/*/' . $key . '.json');
        }

        return $hit === [] ? $runList : $runList->withoutFromSelection($hit);
    }

    /**
     * A cached failure/error always re-runs (SPEC.md §6.2), so an object carrying one is no
     * use here either.
     *
     * @param array<string, TestResultArray> $results
     */
    private static function holdsARerun(array $results): bool
    {
        $reader = self::$reader;

        if ($reader === null) {
            return false;
        }

        foreach ($results as $result) {
            if ($reader->shouldRerun($result['status'])) {
                return true;
            }
        }

        return false;
    }

    /** SPEC.md §6.1: `off` → Off, partial selection → ResultsOnly, no usable baseline → Record. */
    private static function decideMode(
        Config $config,
        ConfigurationReader $reader,
        ?Graph $graph,
        string $branch,
        bool $hasDriver,
    ): Mode {
        if ($config->mode === 'off') {
            return Mode::Off;
        }

        if ($reader->hasPartialSelection()) {
            return Mode::ResultsOnly;
        }

        if ($config->mode === 'record' || $graph === null || $graph->recordedSha($branch) === null) {
            if (! $hasDriver) {
                self::warn('no coverage driver: install pcov or enable xdebug coverage (in-process replay disabled)');

                return Mode::Off;
            }

            return Mode::Record;
        }

        return Mode::Replay;
    }

    /** @param array<string, mixed> $fingerprint */
    private static function reconcileGraph(?Graph $graph, array $fingerprint, string $defaultBranch): ?Graph
    {
        if ($graph === null) {
            return null;
        }

        $structuralDrift = Fingerprint::structuralDrift($graph->fingerprint(), $fingerprint);

        if ($structuralDrift !== []) {
            self::warn(sprintf('structural change (%s): recording a fresh baseline', implode(', ', $structuralDrift)));

            return null;
        }

        $environmentalDrift = Fingerprint::environmentalDrift($graph->fingerprint(), $fingerprint);

        if ($environmentalDrift !== []) {
            self::warn(sprintf('environment change (%s): cached results cleared', implode(', ', $environmentalDrift)));
            $graph->clearResults();
        }

        $graph->setDefaultBranch($defaultBranch);

        return $graph;
    }

    /**
     * The remote cache for this in-process run (SPEC.md §9), mirrored from
     * `Console\Runner\RunPipeline::openRemote()`: built from config and opened once with
     * `begin()` (a git mirror fetch); `end()` happens once from {@see self::persistInProcess()}.
     * A backend that cannot open only warns — a remote is an accelerator, never a
     * dependency — and `remote => null`/`''` (the common case) never even builds one.
     */
    private static function openRemote(Config $config, string $stateDir, string $root): void
    {
        $remote = RemoteCacheFactory::fromConfig($config, $stateDir);

        if ($remote->name() === 'null') {
            return;
        }

        $remote->begin();
        self::$remoteOpen = true;
        self::$objects = new ObjectStore($remote, $stateDir, ProjectKey::for($root));

        Warnings::debug('remote: ' . $remote->name() . ' opened (push: ' . $config->remotePush . ')');
    }

    /** `end()` exactly once, whatever this run settled on. */
    private static function closeRemote(): void
    {
        if (! self::$remoteOpen) {
            return;
        }

        self::$remoteOpen = false;
        self::$objects?->remote()->end();
    }

    /**
     * `ObjectStore::graphOf()` for the branch itself, then the configured baseline
     * candidates (Config::baselineCandidates()) — the implicit `pull` a developer (or an
     * ephemeral CI job) starting with nothing gets from `phpunit-replay run`
     * (docs/INTERNALS.md "Pipeline changes"), mirrored here for in-process mode. The first
     * candidate the remote actually has becomes this machine's local graph too, so the next
     * run needs no remote at all. Null (record fresh) when there is no remote, or it holds
     * none of the candidates.
     */
    private static function pullStartingGraph(Config $config, string $branch, string $defaultBranch, string $root, GraphStore $store): ?Graph
    {
        $objects = self::$objects;

        if ($objects === null) {
            return null;
        }

        $seen = [];

        foreach ([$branch, ...$config->baselineCandidates($defaultBranch)] as $candidate) {
            if ($candidate === '' || $candidate === 'HEAD' || isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;
            $graph = $objects->graphOf($candidate, $root);

            if ($graph === null) {
                continue;
            }

            // Every result this graph already carries came from the remote (this machine
            // has never recorded anything): counted as replayed-remote the same way a
            // by-key hit in self::replayAffectedFromRemote() is.
            foreach ($graph->branches() as $recorded) {
                foreach (array_keys($graph->ownResults($recorded)) as $testId) {
                    self::$remoteTestIds[$testId] = true;
                }
            }

            $store->save($graph);
            Warnings::debug('remote: adopted the ' . $candidate . ' baseline as the local graph');

            return $graph;
        }

        return null;
    }

    private static function freshGraph(string $root, Graph $previous): Graph
    {
        $graph = new Graph($root);
        $graph->setFingerprint($previous->fingerprint());
        $graph->setDefaultBranch($previous->defaultBranch());

        return $graph;
    }

    private static function recordSummaryLine(int $tests): ?string
    {
        $graph = self::$graph;

        if ($graph === null) {
            return null;
        }

        $stats = $graph->stats();
        $bytes = @filesize(rtrim(self::stateDir(), '/') . '/graph.json');

        return Summary::recorded(
            $tests,
            $stats['test_files'],
            $stats['files'],
            $stats['edges'],
            $bytes !== false ? $bytes : 0,
            microtime(true) - self::startedAt(),
            self::$persist ? self::$branch : null,
            self::$persist ? self::$head : null,
        )->format();
    }

    /**
     * Everything this process observed, with replayed tests restored to their cached
     * status/time/assertions/message.
     *
     * @return array<string, TestResultArray>
     */
    private static function resultsForPersist(string $root): array
    {
        $out = [];

        foreach (self::$collector !== null ? self::$collector->all() : [] as $testId => $result) {
            if (isset(self::$replayed[$testId])) {
                $out[$testId] = self::$replayed[$testId];

                continue;
            }

            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $relative = Paths::relative($root, $file);

                if ($relative === null) {
                    unset($result['file']);
                } else {
                    $result['file'] = $relative;
                }
            }

            $out[$testId] = $result;
        }

        return $out;
    }

    /**
     * @param array<string, list<string>> $map absolute test file => absolute source files, or table names
     * @return array<string, list<string>>
     */
    private static function relativiseMap(array $map, string $root, bool $relativiseValues): array
    {
        $out = [];

        foreach ($map as $testFile => $values) {
            $testFileRelative = Paths::relative($root, $testFile);

            if ($testFileRelative === null) {
                continue;
            }

            if (! $relativiseValues) {
                $out[$testFileRelative] = $values;

                continue;
            }

            $relative = [];

            foreach ($values as $value) {
                $valueRelative = Paths::relative($root, $value);

                if ($valueRelative !== null) {
                    $relative[] = $valueRelative;
                }
            }

            $out[$testFileRelative] = $relative;
        }

        return $out;
    }

    private static function relativeTestFile(Graph $graph, string $absolute): ?string
    {
        if ($absolute === '') {
            return null;
        }

        $real = @realpath($absolute);

        return $graph->relative($real !== false ? $real : $absolute);
    }

    /** @return array{0: string, 1: string}|null class and method of `Class::method[#dataSet]`, else null */
    private static function methodOf(string $testId): ?array
    {
        $separator = strpos($testId, '::');

        if ($separator === false || $separator === 0) {
            return null;
        }

        $class = substr($testId, 0, $separator);
        $method = substr($testId, $separator + 2);
        $dataSet = strpos($method, '#');

        if ($dataSet !== false) {
            $method = substr($method, 0, $dataSet);
        }

        return $method === '' ? null : [$class, $method];
    }

    private static function scanDepends(string $className): void
    {
        if (isset(self::$scannedForDepends[$className])) {
            return;
        }

        self::$scannedForDepends[$className] = true;

        try {
            if (! class_exists($className)) {
                return;
            }

            $parser = MetadataRegistry::parser();

            foreach ((new ReflectionClass($className))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ($parser->forMethod($className, $method->getName()) as $metadata) {
                    if ($metadata instanceof DependsOnMethod) {
                        self::$dependedUpon[$metadata->className() . '::' . $metadata->methodName()] = true;
                    }
                }
            }
        } catch (Throwable) {
            // Metadata is best effort: a malformed attribute must never break the run.
        }
    }

    private static function replayKind(Decision $decision): string
    {
        return match (true) {
            $decision instanceof ReplayPass => 'pass',
            $decision instanceof ReplaySkipped => 'skipped',
            $decision instanceof ReplayIncomplete => 'incomplete',
            default => 'unknown',
        };
    }

    private static function resolveRoot(Configuration $configuration): string
    {
        if ($configuration->hasConfigurationFile()) {
            return dirname($configuration->configurationFile());
        }

        return getcwd() ?: '.';
    }

    private static function newRunId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    private static function warn(string $message): void
    {
        Warnings::warn($message);
    }

    private static function assertBooted(): void
    {
        if (self::$mode === null) {
            throw self::notBooted();
        }
    }

    private static function notBooted(): LogicException
    {
        return new LogicException('ReplayState::boot() has not been called.');
    }
}
