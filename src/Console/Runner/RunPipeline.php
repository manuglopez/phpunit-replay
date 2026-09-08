<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

use FilesystemIterator;
use Manuglopez\Replay\Analysis\FactsCache;
use Manuglopez\Replay\Analysis\StaticEdges;
use Manuglopez\Replay\Cache\BaselineWriter;
use Manuglopez\Replay\Cache\ContentHash;
use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Cache\Remote\NullRemoteCache;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Cache\Remote\RemoteCache;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Cache\RunContext;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\BaselineResolver;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Change\LastRunTree;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\ExplainFormatter;
use Manuglopez\Replay\Hermeticity\DivergenceLog;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Laravel\LaravelDetector;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\Laravel\ParallelIsolation;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Report\CoverageMerger;
use Manuglopez\Replay\Report\DryRunSummary;
use Manuglopez\Replay\Report\JUnitMerger;
use Manuglopez\Replay\Report\Summary;
use Manuglopez\Replay\Report\VerifySummary;
use Manuglopez\Replay\Select\ReplaySet;
use Manuglopez\Replay\Select\RunList;
use Manuglopez\Replay\Select\RunListBuilder;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Support\Paths;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * SPEC.md §3.1 and docs/INTERNALS.md "Wrapper pipeline — detailed algorithm (phase 1,
 * filtered mode)": everything the CLI commands delegate to. Never throws: any failure
 * — anticipated (no git, no phpunit.xml, no coverage driver, ...) or not — degrades to
 * running `vendor/bin/phpunit` exactly as the user would have, returning its exit code
 * — except a degraded `record` (SPEC.md §3.3), whose exit code instead reports whether
 * *it* did its own job (publishing a graph), since nothing gates merges on it the way
 * `run`'s exit code gates on the tests actually passing (see {@see self::degrade()}).
 */
final class RunPipeline
{
    /**
     * Bug fix: `record` exists to publish a baseline graph, and a degraded run never
     * writes one (no `MODE=record` env, no extension bootstrapped, no run partial) — so
     * forwarding PHPUnit's own exit code straight through, the way {@see self::degrade()}
     * does for every other caller, would report "0" for a `record` that recorded nothing,
     * which is indistinguishable from success. `EXCEPTION_EXIT` mirrors PHPUnit's own
     * `ShellExitCodeCalculator` convention (0 pass, 1 test failures, 2 "something outside
     * the tests themselves went wrong") — a degraded `record` is squarely the third case.
     * Used only when `RunRequest::$record` is true (the `record` command itself, not
     * `run`'s own implicit first-baseline pass) and PHPUnit's own exit code was 0 — a
     * nonzero PHPUnit exit code already turns the caller's attention to the run, so it is
     * left untouched.
     */
    private const DEGRADED_RECORD_EXIT_CODE = 2;

    private RunRequest $request;

    private float $startedAt = 0.0;

    private Git $git;

    private ?string $root = null;

    private string $phpunitBin = '';

    private string $configFile = '';

    private string $stateDir = '';

    private string $branch = 'HEAD';

    private bool $persist = false;

    private string $defaultBranch = 'main';

    private ?string $head = null;

    private bool $ciMode = false;

    private string $driverName = 'none';

    /** @var list<string> */
    private array $iniFlags = [];

    private ConfigurationReader $reader;

    private Config $config;

    private TestPaths $testPaths;

    private WatchPatterns $watch;

    /** @var array<string, mixed> */
    private array $fingerprint = [];

    /**
     * The `static_declaration_edges` collaborator (SPEC.md §4.3.1), built once per pass and
     * left null when the flag is off — which is what keeps the graph byte-identical.
     */
    private ?StaticEdges $staticEdges = null;

    private ?Graph $graph = null;

    private GraphStore $store;

    private RemoteCache $remote;

    private ?ObjectStore $objects = null;

    private bool $remoteOpen = false;

    /** @var array<string, true> test ids whose cached result came from the remote, not from this machine */
    private array $remoteTestIds = [];

    /** @var array{branch: string, sha: string, source: string, distance: int}|null */
    private ?array $baseline = null;

    private Quarantine $quarantine;

    private ?string $generatedXml = null;

    private ?string $runDir = null;

    public function run(RunRequest $request): int
    {
        $this->request = $request;
        $this->startedAt = microtime(true);

        try {
            $reason = $this->resolveEnvironment($request);

            if ($reason !== null) {
                return $this->degrade($request, $reason);
            }

            $this->loadGraph($request);

            if ($this->reader->hasPartialSelection()) {
                return $this->runResultsOnly($request);
            }

            if ($this->graph === null || $request->record) {
                return $this->runRecord($request);
            }

            return $this->runReplay($request);
        } catch (Throwable $e) {
            return $this->degrade($request, 'unexpected error (' . $e->getMessage() . '): degrading to a plain PHPUnit run');
        } finally {
            $this->closeRemote();
            $this->cleanup();
        }
    }

    /**
     * `phpunit-replay verify` (SPEC.md §12.2): a full-suite recording pass that keeps the
     * existing graph (rather than discarding it the way a plain `record` would) so every
     * result can be compared against what a normal replay pass would have served from
     * cache.
     */
    public function runVerify(RunRequest $request): int
    {
        $this->request = $request;
        $this->startedAt = microtime(true);

        try {
            $reason = $this->resolveEnvironment($request);

            if ($reason !== null) {
                return $this->degrade($request, $reason);
            }

            $this->loadGraph($request);

            return $this->verify($request);
        } catch (Throwable $e) {
            return $this->degrade($request, 'unexpected error (' . $e->getMessage() . '): degrading to a plain PHPUnit run');
        } finally {
            $this->closeRemote();
            $this->cleanup();
        }
    }

    /**
     * Steps 1-5: root & tools, config & state, driver, PHPUnit configuration object,
     * fingerprint. Returns a degrade reason, or null when everything resolved.
     */
    private function resolveEnvironment(RunRequest $request): ?string
    {
        $this->git = new Git($request->cwd);

        if (! Git::available() || ! $this->git->isRepository() || ! $this->git->hasCommits()) {
            return 'git repository with at least one commit required';
        }

        $locator = new ProjectLocator();
        $root = $locator->resolveRoot($this->git);

        if ($root === null) {
            return 'git repository with at least one commit required';
        }

        $this->root = $root;
        $this->git = new Git($root);

        $phpunitBin = $locator->resolvePhpunitBin($root);

        if ($phpunitBin === null) {
            return 'PHPUnit binary not found: ' . $root . '/vendor/bin/phpunit';
        }

        $this->phpunitBin = $phpunitBin;

        $configFile = $locator->resolveConfigFile($root, $request->phpunitArgs);

        if ($configFile === null) {
            return 'filtered mode needs phpunit.xml (or phpunit.xml.dist)';
        }

        $this->configFile = $configFile;

        $config = Config::load($root);
        $this->config = $config;
        $this->stateDir = StateDirectory::resolve($config->stateDir, $root);
        $this->quarantine = Quarantine::load($this->stateDir);
        $this->quarantine->setReleaseAfter($config->quarantineReleaseAfter);

        $branch = $this->git->currentBranch();
        $this->persist = $branch !== null;
        $this->branch = $branch ?? 'HEAD';
        $this->defaultBranch = $config->defaultBranch ?? $this->git->defaultBranch() ?? 'main';
        $this->head = $this->git->currentSha();
        $this->ciMode = RunContext::ciDetected();

        $this->driverName = DriverDetector::loadedExtension() ?? 'none';
        $this->iniFlags = match ($this->driverName) {
            'pcov' => ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $root],
            'xdebug' => ['-d', 'xdebug.mode=coverage'],
            default => [],
        };

        $configuration = $locator->buildConfiguration($configFile, $request->phpunitArgs);
        $this->reader = new ConfigurationReader($configuration);

        if ($this->reader->repeatOrRetryRequested()) {
            return '--repeat/--retry requested: the test id it changes results on is not stable across runs, degrading to a plain PHPUnit run';
        }

        // Bug fix: `record` shares this same `run()` entry point with `run` itself
        // (RecordCommand just sets RunRequest::$record = true), and `run()` used to check
        // `hasPartialSelection()` before ever looking at `$request->record` — so
        // `record -- --filter=X` (or --group/--testsuite/an explicit path) silently took
        // the results-only branch (SPEC.md §3.1's "disable selection" paragraph, meant for
        // `run`), which merges a few already-known tests' results at most and returns,
        // without ever recording an edge or publishing a baseline. That is exactly the
        // "record produced nothing" case {@see self::DEGRADED_RECORD_EXIT_CODE} exists to
        // flag — only unreachable through this path, because results-only mode is a
        // deliberate destination for `run`, not a degrade, so nothing there turned the exit
        // code red. RecordCommand's own docblock already claims `record` runs "the full
        // suite ... unconditionally (no selection)" (SPEC.md §3.3); a partial run cannot
        // produce the one thing `record` exists to produce (a complete, trustworthy
        // baseline — pruning and the published sha both assume the whole suite ran), so
        // there is nothing worth salvaging from it under record's name. Checked here, before
        // `loadGraph()` ever runs, so an existing graph is never even touched. Routed
        // through `degrade()` like every other reason on this list: the user's own
        // selection still runs, for real, via the plain unwrapped `PhpunitProcess` fallback
        // (never breaking the run), the graph is left completely alone, and the
        // `$request->record` rule already in `degrade()` turns a passing fallback into exit
        // 2 instead of a false "0". `run` and `verify` are unaffected: only
        // `$request->record` reaches this branch, so they keep handling a partial selection
        // the way they always have ({@see self::runResultsOnly()}, `verify()`'s own
        // `hasPartialSelection()` gate).
        if ($request->record && $this->reader->hasPartialSelection()) {
            return 'record does not accept a partial PHPUnit selection (--filter/--exclude-filter/--group/--exclude-group/--testsuite/--exclude-testsuite/an explicit path): it always records the complete suite, unconditionally (SPEC.md §3.3) — use `run` or `verify` instead';
        }

        $this->testPaths = TestPaths::fromConfiguration($configuration, $root);

        $this->watch = new WatchPatterns();
        $this->watch->useDefaults($root, $this->testPaths->directories());

        if ($config->watch !== []) {
            $this->watch->add($config->watch);
        }

        $this->fingerprint = Fingerprint::compute($root, $this->driverName, $config->staticDeclarationEdges);

        if ($config->staticDeclarationEdges) {
            $this->staticEdges = new StaticEdges(
                $root,
                SourceScope::fromProjectRoot($root, $configuration),
                new FactsCache($this->stateDir, $root),
            );
        }
        $this->openRemote($request, $root, $config);

        Warnings::debug(sprintf(
            'root=%s branch=%s(default:%s) head=%s driver=%s stateDir=%s',
            $root,
            $this->branch,
            $this->defaultBranch,
            $this->head ?? 'unknown',
            $this->driverName,
            $this->stateDir,
        ));

        return null;
    }

    /** Step 6: load the cached graph and reconcile it against the current fingerprint. */
    private function loadGraph(RunRequest $request): void
    {
        $this->store = new GraphStore($this->stateDir, $this->root ?? '');
        $this->graph = $request->fresh ? null : $this->store->load();

        if ($this->graph !== null && ! $this->reconcile($this->graph, 'the cached baseline')) {
            $this->graph = null;
        }

        // Nothing cached locally (first run on this machine, or a structural change just
        // invalidated what was there): a remote may already hold a baseline for this
        // branch, or for the nearest one (SPEC.md §9, docs/INTERNALS.md "Pipeline changes").
        if ($this->graph === null && ! $request->fresh) {
            $this->graph = $this->pullStartingGraph();
        }

        if ($this->graph === null) {
            return;
        }

        $this->graph->setDefaultBranch($this->defaultBranch);
        $this->resolveBaseline($this->graph);
    }

    /**
     * Fingerprint reconciliation, shared by the local and the remote baseline: a structural
     * mismatch makes the graph unusable (false), environmental drift only makes its cached
     * results unusable.
     */
    private function reconcile(Graph $graph, string $what): bool
    {
        $structuralDrift = Fingerprint::structuralDrift($graph->fingerprint(), $this->fingerprint);

        if ($structuralDrift !== []) {
            Warnings::warn(sprintf(
                'structural change (%s): %s cannot be used, recording a fresh baseline',
                implode(', ', $structuralDrift),
                $what,
            ));

            return false;
        }

        $environmentalDrift = Fingerprint::environmentalDrift($graph->fingerprint(), $this->fingerprint);

        if ($environmentalDrift !== []) {
            Warnings::warn(sprintf('environment change (%s): cached results cleared', implode(', ', $environmentalDrift)));
            $graph->clearResults();
        }

        return true;
    }

    /**
     * `ObjectStore::graph(<branch>) ?? graph(<nearest candidate>)`, reconciled and saved
     * locally as this machine's starting graph — the implicit `pull` SPEC.md §12 describes
     * for a developer (or an ephemeral CI job) starting with nothing. Every result it
     * carries is remembered as remote-sourced, so the summary can say how much of the pass
     * came from somebody else's machine.
     */
    private function pullStartingGraph(): ?Graph
    {
        $objects = $this->objects;

        if ($objects === null) {
            return null;
        }

        $root = $this->root ?? '';

        foreach ($this->baselineCandidates() as $branch) {
            $graph = $objects->graphOf($branch, $root);

            if ($graph === null || ! $this->reconcile($graph, 'the remote baseline for ' . $branch)) {
                continue;
            }

            foreach ($graph->branches() as $recorded) {
                foreach (array_keys($graph->ownResults($recorded)) as $testId) {
                    $this->remoteTestIds[$testId] = true;
                }
            }

            $this->store->save($graph);
            Warnings::debug('remote: adopted the ' . $branch . ' baseline as the local graph');

            return $graph;
        }

        return null;
    }

    /**
     * DECISIONS.md D-039: tell the graph which baseline to fall back to before the default
     * branch. Only when it lives on ANOTHER branch — the current branch's own baseline is
     * already the first thing `Graph::results()` reads.
     */
    private function resolveBaseline(Graph $graph): void
    {
        $head = $this->head;

        if ($head === null) {
            return;
        }

        $resolved = (new BaselineResolver($this->git, $graph, $this->objects, $this->config, $this->defaultBranch))
            ->resolve($this->branch, $head);

        if ($resolved === null) {
            return;
        }

        $this->baseline = $resolved;

        if ($resolved['branch'] !== $this->branch) {
            $graph->setNearestBranch($resolved['branch']);
            Warnings::debug(sprintf(
                'baseline %s@%s (%s, %d files away)',
                $resolved['branch'],
                substr($resolved['sha'], 0, 7),
                $resolved['source'],
                $resolved['distance'],
            ));
        }
    }

    /** @return list<string> the branch itself, then the configured baseline candidates */
    private function baselineCandidates(): array
    {
        $candidates = [$this->branch, ...$this->config->baselineCandidates($this->defaultBranch)];
        $seen = [];

        foreach ($candidates as $branch) {
            if ($branch !== '' && $branch !== 'HEAD') {
                $seen[$branch] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * The remote cache for this pass (SPEC.md §9): built from config, opened once with
     * `begin()` (which for a git mirror is a fetch) and closed once with `end()` in the
     * pipeline's `finally`. `--no-remote` and `remote => null` both leave it closed, and a
     * backend that cannot open only warns: a remote is an accelerator, never a dependency.
     */
    private function openRemote(RunRequest $request, string $root, Config $config): void
    {
        $this->remote = new NullRemoteCache();
        $this->objects = null;
        $this->remoteOpen = false;
        $this->remoteTestIds = [];

        if ($request->noRemote) {
            Warnings::debug('remote: disabled for this run (--no-remote)');

            return;
        }

        $remote = RemoteCacheFactory::fromConfig($config, $this->stateDir);

        if ($remote->name() === 'null') {
            return;
        }

        $remote->begin();
        $this->remote = $remote;
        $this->remoteOpen = true;
        $this->warnRemote();
        $this->objects = new ObjectStore($remote, $this->stateDir, ProjectKey::shared($root));

        Warnings::debug('remote: ' . $remote->name() . ' opened (push: ' . $config->remotePush . ')');
    }

    /** `end()` exactly once, whatever happened during the pass. */
    private function closeRemote(): void
    {
        if (! $this->remoteOpen) {
            return;
        }

        $this->remoteOpen = false;
        $this->remote->end();
        $this->warnRemote();
    }

    private function warnRemote(): void
    {
        $error = $this->remote->lastError();

        if ($error !== null) {
            Warnings::warn('remote (' . $this->remote->name() . '): ' . $error);
        }
    }

    /**
     * Step 7: partial CLI selection (--filter, --group, --testsuite, an explicit path, ...).
     * By this point `$request->record` is always false: a `record` carrying a partial
     * selection already degraded in {@see self::resolveEnvironment()}, before `run()`
     * even reached the `hasPartialSelection()` check that routes here.
     *
     * Unlike {@see self::runRecord()} and {@see self::verify()}, this method never checks
     * `$this->driverName` for a missing coverage driver, deliberately: it always calls
     * {@see self::runPhpunit()} with `$iniFlags = []` — no `pcov.enabled=1`/
     * `xdebug.mode=coverage`, regardless of whether a driver is actually loaded — and
     * always calls `GraphUpdater::apply(..., recordsEdges: false, ...)` below, so no
     * coverage data is ever collected or required; the extension itself skips its own
     * driver-detection block entirely for `Mode::ResultsOnly`
     * ({@see \Manuglopez\Replay\PHPUnit\ReplayExtension::bootstrap()}). Results-only mode
     * only merges pass/fail/time/assertions for test files the graph already knows about,
     * sourced from PHPUnit's own event subscribers, never from coverage. That is precisely
     * what makes `run --filter` (and `--group`/`--testsuite`/an explicit path) keep working
     * driver-agnostically, per SPEC.md §16 acceptance criterion 7 ("Without pcov or Xdebug
     * the package disables itself with a warning and PHPUnit works normally") — degrading
     * here for a missing driver would instead break acceptance criterion 6 for every
     * `run --filter` on a machine with no driver at all, for no correctness gain: there is
     * no edge or baseline this path could have recorded either way.
     */
    private function runResultsOnly(RunRequest $request): int
    {
        // Bug fix: this branch is reached before the record/replay decision (`run()`
        // checks `hasPartialSelection()` first), so it never reaches the dry-run block
        // in `runReplay()` — a `--filter`/`--group`/`--testsuite`/explicit-path run had
        // no dry-run check of its own at all, and executed for real regardless of the
        // flag. There is no TIA plan to report here (no RunListBuilder involved: the
        // user's own phpunit-args already narrowed the selection), so the honest thing
        // to print is that PHPUnit would run exactly that selection, not a computed one.
        if ($request->dryRun) {
            fwrite(STDOUT, self::dryRunLine('would run only your own selection (--filter/--group/--testsuite/an explicit path); no plan to compute for a partial selection') . PHP_EOL);

            return 0;
        }

        $xml = (new ConfigurationWriter())->withExtensionOnly($this->configFile);
        $this->generatedXml = $xml;

        $runId = self::newRunId();
        $this->runDir = $this->stateDir . '/runs/' . $runId;

        $exitCode = $this->runPhpunit(
            $xml,
            [],
            $request->phpunitArgs,
            false,
            $this->baseEnv('results-only', $runId),
            $this->root ?? $request->cwd,
        );

        if ($this->graph !== null) {
            $partial = RunPartial::load($this->runDir);

            if ($partial !== null) {
                $root = $this->root ?? '';

                if (LaravelDetector::enabled($root, $this->config)) {
                    $partial = LaravelIntegration::augment($partial, $root);
                }

                $updater = new GraphUpdater($this->graph, $root, new ContentKey($root), $this->quarantine, $this->staticEdges);
                $updater->apply($partial, $this->branch, recordsEdges: false, complete: false);
                $this->store->save($this->graph);
                $this->quarantine->save($this->stateDir);
            }
        }

        return $exitCode;
    }

    /** Step 8: full suite, recording. */
    private function runRecord(RunRequest $request): int
    {
        if ($this->driverName === 'none') {
            return $this->degrade($request, 'no coverage driver: install pcov or enable xdebug coverage');
        }

        // Bug fix: this is the path `run --dry-run` takes on a project with no cached
        // baseline yet (SPEC.md §3.1 "Record" branch) — the driver check above already
        // funnels a broken environment through degrade(), so anything reaching here has
        // a real (if unfiltered) plan: the whole suite. Checked before anything below
        // touches disk (no Graph, no run id, no PHPUnit process) so a dry run never
        // writes state and never launches PHPUnit.
        if ($request->dryRun) {
            fwrite(STDOUT, self::dryRunLine('would record the full suite (no cached baseline)') . PHP_EOL);

            return 0;
        }

        $root = $this->root ?? $request->cwd;

        $graph = new Graph($root);
        $graph->setFingerprint($this->fingerprint);
        $graph->setDefaultBranch($this->defaultBranch);
        $this->graph = $graph;

        $xml = (new ConfigurationWriter())->withExtensionOnly($this->configFile);
        $this->generatedXml = $xml;

        $runId = self::newRunId();
        $this->runDir = $this->stateDir . '/runs/' . $runId;

        [$phpunitArgsForRun, $coveragePhpTarget] = $this->redirectCoveragePhp($request->phpunitArgs, $root);

        $exitCode = $this->runPhpunit(
            $xml,
            $this->iniFlags,
            $phpunitArgsForRun,
            true,
            $this->baseEnv('record', $runId),
            $root,
        );

        $partial = RunPartial::load($this->runDir);

        if ($partial === null) {
            Warnings::warn('the PHPUnit run produced no run partial; nothing recorded');

            return $exitCode;
        }

        if (LaravelDetector::enabled($root, $this->config)) {
            $partial = LaravelIntegration::augment($partial, $root);
        }

        $complete = ! (bool) ($partial->meta['truncated'] ?? false) && in_array($exitCode, [0, 1], true);

        $updater = new GraphUpdater($graph, $root, new ContentKey($root), $this->quarantine, $this->staticEdges);
        $applied = $updater->apply($partial, $this->branch, recordsEdges: true, complete: $complete);
        $this->quarantine->save($this->stateDir);

        if ($this->fingerprintDrifted($partial)) {
            return $exitCode;
        }

        $this->persistAfterRun($updater, $complete, new ChangedFiles($root, $this->git));
        $this->pushAfterRun($graph, $applied['touched'], $complete);
        $this->printRecordSummary($partial);

        // A full record pass replays nothing: whatever coverage PHPUnit collected for this
        // run is already complete on its own (SPEC.md §3.2 last paragraph).
        if ($coveragePhpTarget !== null) {
            $this->finalizeCoveragePhp($this->runDir . '/coverage.php', $coveragePhpTarget, $root, []);
        }

        return $exitCode;
    }

    /**
     * SPEC.md §12.2: full suite, `record` mode, graph kept (unlike {@see self::runRecord()},
     * which always starts from an empty one), so this pass has both a real result for every
     * test and the cached result a fast `run` lane would have served instead. Reports how
     * much of the suite that lane would have covered ({@see self::replaySetBeforeVerify()})
     * and how many of the cached results turn out to be wrong (a divergence).
     */
    private function verify(RunRequest $request): int
    {
        if ($this->driverName === 'none') {
            return $this->degrade($request, 'no coverage driver: install pcov or enable xdebug coverage');
        }

        $root = $this->root ?? $request->cwd;
        $graph = $this->graph;

        if ($graph === null) {
            $graph = new Graph($root);
            $graph->setFingerprint($this->fingerprint);
            $graph->setDefaultBranch($this->defaultBranch);
        }

        $this->graph = $graph;
        $oldResults = $graph->results($this->branch);

        // Decided here, at the one point in this method where "before this pass touched
        // anything" is literally true: before the generated `.phpunit-replay.xml` exists in
        // the working tree, before PHPUnit has run a line of the suite (and with it whatever
        // files the suite writes), and before `apply()` below rewrites the graph's edges,
        // content keys and results. It is also the same point in the pipeline `run` decides
        // at ({@see self::runReplay()}), which is what makes the two answers comparable.
        $replaySet = $this->replaySetBeforeVerify($root, $graph);

        $xml = (new ConfigurationWriter())->withExtensionOnly($this->configFile);
        $this->generatedXml = $xml;

        $runId = self::newRunId();
        $this->runDir = $this->stateDir . '/runs/' . $runId;

        // Bug fix: this used to construct a PhpunitProcess directly, bypassing
        // runPhpunit() — the one branch that chooses between PhpunitProcess and
        // ParatestProcess (SPEC.md §13) — so `verify --parallel=N` silently ran
        // sequentially regardless of the flag. Routing through runPhpunit(), exactly
        // like runRecord() does, is what makes `RunRequest::$parallel` actually take
        // effect here.
        $exitCode = $this->runPhpunit(
            $xml,
            $this->iniFlags,
            $request->phpunitArgs,
            true,
            $this->baseEnv('record', $runId),
            $root,
        );

        $partial = RunPartial::load($this->runDir);

        if ($partial === null) {
            Warnings::warn('the PHPUnit run produced no run partial; nothing verified');

            return $exitCode;
        }

        if (LaravelDetector::enabled($root, $this->config)) {
            $partial = LaravelIntegration::augment($partial, $root);
        }

        // Bug fix: this used to pass `recordsEdges: true` (and a `$complete` derived from
        // the run alone) whatever the user asked PHPUnit to run, so a `verify` carrying a
        // partial CLI selection — `--filter`, `--group`, `--testsuite`, an explicit path —
        // rewrote the graph from a run that never covered the suite. SPEC.md §15 scenario 6
        // forbids exactly that for `run` ({@see self::runResultsOnly()}, results-only mode);
        // `verify` is the same full-suite recording pass and owes the graph the same
        // guarantee. Three things went wrong without it: the executed test file's edges were
        // replaced by whatever coverage the narrower selection attributed to it (a file
        // autoloaded once per process is credited to whichever test loaded it first, so the
        // dependency set — and therefore the file's content key — depends on the selection);
        // `$complete` then pruned the sibling results the filter excluded, silently deleting
        // cached results; and it published a baseline sha for a partial run.
        $recordsEdges = ! $this->reader->hasPartialSelection();

        $complete = $recordsEdges
            && ! (bool) ($partial->meta['truncated'] ?? false)
            && in_array($exitCode, [0, 1], true);

        // No quarantine passed here: divergences are detected explicitly below (reason
        // 'divergence', not the generic 'flip' GraphUpdater's own detection would use).
        $updater = new GraphUpdater($graph, $root, new ContentKey($root), null, $this->staticEdges);
        $updater->apply($partial, $this->branch, recordsEdges: $recordsEdges, complete: $complete);

        if ($this->fingerprintDrifted($partial)) {
            return $exitCode;
        }

        $newResults = $graph->results($this->branch);

        $wouldReplay = 0;
        $unverified = 0;
        $divergenceEntries = [];

        // Bug fix (the measurement design, not another off-by-one in it): `$wouldReplay`
        // used to be decided right here, by comparing the STORED content key against the one
        // `apply()` had just recomputed from freshly re-observed coverage — `$oldKey !==
        // $newKey` skipped the test. But `verify` re-records edges on this very pass and
        // `Graph::unionEdges()` only ever grows a test file's dependency set, so any test
        // that gained an edge got a new key and dropped out of the count. The figure
        // therefore answered "is this test's dependency set byte-identical to the last
        // recording?" rather than "would the fast lane have served this test from cache?" —
        // the question the README tells people to act on before making `run` a per-PR merge
        // gate. It shrank monotonically with how many passes had run instead of describing
        // the tree: on a real 9056-test suite `9056 − would replay` went 2001 → 1941 → 1502 →
        // → 1253 across four identical passes, never converging, while `run` on the same
        // unchanged tree replayed 9056 of 9056 — because `run` never re-observes anything.
        //
        // It is now decided by `Select\ReplaySet`, off the run list `run` itself builds,
        // against the pre-pass state (see `self::replaySetBeforeVerify()`). That also
        // retires the `$excluded` test that used to sit here — a second, hand-rolled
        // rendering of `shouldRerun`/`Policy::cacheable` that could disagree with the run
        // list on exactly the cases it existed to catch. It disagreed on one for real: a
        // per-test-id `Policy::cacheable()` check excluded only the `#[NotCacheable]` method
        // itself, where `run` puts that method's whole FILE in the run list and re-executes
        // every test in it. `Policy` has no other use in this method, so it is gone.
        //
        // Divergence detection is deliberately left exactly as it was, including its
        // key-equality gate. It is the trustworthy half of the line (0 across six passes on
        // that same real suite) and it errs the safe way: gating on equal keys makes it
        // broader than `$wouldReplay` in one direction (it checks tests `run` would have
        // re-executed anyway) and narrower in the other (it skips tests whose key moved).
        // That second half is no longer silent — those tests are counted as `$unverified`
        // instead of quietly vanishing from the count the way they used to, which is how the
        // old figure hid its own drift. Note `run`'s local replay path never compares content
        // keys at all (`ReplayState::decideFresh()` reads the cached result straight out of
        // the graph; the key only addresses the REMOTE object store), so the tests this gate
        // exempts are precisely the ones whose cached results `run` would still serve and
        // this pass has fresh evidence about — see the CHANGELOG entry.
        foreach (array_keys($partial->results) as $testId) {
            $replayable = $replaySet->has($testId);

            if ($replayable) {
                $wouldReplay++;
            }

            $new = $newResults[$testId] ?? null;
            $old = $oldResults[$testId] ?? null;
            $oldKey = $old['key'] ?? null;
            $newKey = $new['key'] ?? null;

            if ($new === null || $old === null || $oldKey === null || $newKey === null || $oldKey !== $newKey) {
                // The comparison below cannot run for this test. Only worth reporting when
                // the fast lane would have served its cached result regardless.
                if ($replayable) {
                    $unverified++;
                }

                continue;
            }

            $oldClass = GraphUpdater::statusClass($old['status']);

            if ($oldClass === 'fail') {
                // A cached failure/error always reruns unconditionally regardless of the
                // cache (SPEC §6.2), so it was never really "replayed" in the first
                // place: recovering from one is not a divergence (GraphUpdater::detectFlip()
                // excludes the same transition for the same reason). It is not `$unverified`
                // either: `RunListBuilder`'s `rerun` bucket puts the whole file in the run
                // list, so such a test is never in `$replaySet` to begin with.
                continue;
            }

            if ($oldClass === GraphUpdater::statusClass($new['status'])) {
                $this->quarantine->recordStable($testId);

                continue;
            }

            $divergenceEntries[] = [
                'testId' => $testId,
                'k' => $newKey,
                'cached' => $old['status'],
                'actual' => $new['status'],
                'sha' => $this->head,
                'at' => time(),
            ];

            $this->quarantine->recordFlip($testId, $newKey, 'divergence');
        }

        $this->persistAfterRun($updater, $complete, new ChangedFiles($root, $this->git));
        $this->quarantine->save($this->stateDir);

        $lifetime = DivergenceLog::append($this->stateDir, $divergenceEntries);

        $summary = new VerifySummary(
            count($partial->results),
            $wouldReplay,
            count($divergenceEntries),
            $unverified,
            $lifetime['divergences'],
            $lifetime['runs'],
            $exitCode === 0 && $divergenceEntries === [],
        );

        fwrite(STDOUT, $summary->format() . PHP_EOL);

        return $exitCode;
    }

    /**
     * What a `run` on this same tree would have served from cache instead of executing —
     * `verify`'s `would replay` figure (SPEC.md §12.2), decided by asking the very code
     * `run` asks: the git diff against the recorded baseline, through
     * {@see self::computeRunList()}'s `Select\RunListBuilder`, into
     * {@see \Manuglopez\Replay\Select\ReplaySet}. `verify` builds no run list of its own
     * otherwise (it always runs everything), so this is the whole of it.
     *
     * Two decisions worth stating, because both are load-bearing:
     *
     * 1. **Computed up front, not reconstructed afterwards.** The alternative was to snapshot
     *    enough of the "before" state to answer the question after PHPUnit had run. That
     *    means a deep copy of the graph's edges, reverse index, content keys, fingerprint and
     *    results — `GraphUpdater::apply()` mutates all of them in place — plus the quarantine
     *    table, which the divergence loop mutates too; a second serialization surface owing
     *    the graph's schema forever. And it would still be wrong: `RunListBuilder` walks the
     *    test directories and `ChangedFiles` shells out to git, so run after the suite it
     *    would read a working tree the suite (and the generated `.phpunit-replay.xml`) had
     *    already changed. Here the "before" state needs no copy — it is simply now.
     * 2. **The caller's own CLI selection is ignored.** A `verify -- --filter X` still gets
     *    the full-suite run list, so the figure keeps meaning "would a `run` on this tree
     *    have replayed these tests" rather than collapsing to 0 (a partial selection sends
     *    `run` into results-only mode, which replays nothing — a true but useless answer to
     *    a question nobody asked). The filter still narrows which tests the figure is
     *    reported over, through `$partial->results`. This is free rather than deliberate
     *    plumbing: `RunListBuilder` never consults the selection; only `Mode` does.
     *
     * `run` prunes test files missing from disk before building its list and this does not,
     * deliberately: pruning deletes graph state, which a measurement must not do. It cannot
     * change the answer — a pruned file's results are dropped, and an unpruned file's results
     * are excluded anyway (nothing depending on it can put a deleted file anywhere but the
     * selection, i.e. the run list), and either way its tests cannot be in `$partial->results`
     * because they did not run.
     *
     * Known, deliberate under-report: the remote object store is not consulted. `run` can serve
     * an *affected* test file from the remote when another machine already ran exactly that
     * content ({@see self::replayFromRemote()}, SPEC.md §9), and this counts those tests as
     * executing. Consulting the remote would mean fetching objects and merging them into the
     * graph — a side effect a measurement must not have — and would make the answer depend on
     * remote state at this instant rather than on the tree. Erring low is the safe direction
     * for a figure whose whole purpose is to justify trusting the fast lane.
     */
    private function replaySetBeforeVerify(string $root, Graph $graph): ReplaySet
    {
        $sha = $this->baseline['sha'] ?? $graph->recordedSha($this->branch);

        if ($sha === null) {
            // No baseline to replay from: `run` would record the whole suite instead
            // ({@see self::runRecord()}), replaying nothing.
            Warnings::debug('would replay: no baseline sha for ' . $this->branch . '; a run here would record instead');

            return ReplaySet::none();
        }

        $changedFiles = new ChangedFiles($root, $this->git);
        $changed = $changedFiles->since($sha);

        if ($changed === null) {
            // The baseline is not an ancestor of HEAD: `run` would record a fresh baseline
            // and replay nothing ({@see self::runReplay()}'s own `$changed === null` branch).
            // Said out loud rather than left silent, for the reason that branch warns too:
            // `verify` proceeds normally either way (it is a recording pass regardless), so a
            // figure collapsing to 0 with no trace would read as a broken cache rather than
            // as an unreachable baseline.
            Warnings::warn(sprintf(
                'would replay: baseline %s is not an ancestor of HEAD; a run here would record a fresh baseline and replay nothing',
                substr($sha, 0, 7),
            ));

            return ReplaySet::none();
        }

        $lastRun = LastRunTree::load($this->stateDir);

        if ($lastRun !== null && $lastRun->branch === $this->branch) {
            $changed = $lastRun->filterUnchanged($changed, $changedFiles);
        }

        /** @var list<string> $runList */
        $runList = $this->computeRunList($graph, $changed, $this->branch, $root)['runList'];

        return ReplaySet::against($graph, $this->branch, $runList);
    }

    /** Step 9: replay — compute what changed, select the run list, run it (or skip it). */
    private function runReplay(RunRequest $request): int
    {
        $graph = $this->graph;

        if ($graph === null) {
            // Never actually reached (runReplay is only called when $this->graph !== null),
            // kept for defensive symmetry with the sha === null branch below.
            return $this->runRecord($request);
        }

        $sha = $this->baseline['sha'] ?? $graph->recordedSha($this->branch);

        if ($sha === null) {
            $this->graph = null;

            return $this->runRecord($request);
        }

        $root = $this->root ?? $request->cwd;
        $changedFiles = new ChangedFiles($root, $this->git);
        $changed = $changedFiles->since($sha);

        if ($changed === null) {
            Warnings::warn(sprintf('baseline %s is not an ancestor of HEAD: recording a fresh baseline', substr($sha, 0, 7)));
            $this->graph = null;

            return $this->runRecord($request);
        }

        $lastRun = LastRunTree::load($this->stateDir);

        if ($lastRun !== null && $lastRun->branch === $this->branch) {
            $changed = $lastRun->filterUnchanged($changed, $changedFiles);
        }

        // A deleted/renamed known test file is dropped from the selection (it no longer
        // exists to run), so it may never end up part of any run list at all; pruning it
        // from the graph is a disk-existence check, not a coverage question, so it can
        // (and must) happen here regardless of whether anything ends up executing at all
        // (SPEC §7.3).
        if ($changed !== []) {
            $graph->pruneMissingTestFiles();
            $graph->pruneResultsForMissingFiles($this->branch);
        }

        $data = $this->computeRunList($graph, $changed, $this->branch, $root);
        /** @var list<string> $runList */
        $runList = $data['runList'];

        // SPEC.md §9: an affected test file whose exact content another machine has
        // already run is replayed from the remote instead of executed here.
        $runList = $this->replayFromRemote($graph, $data['list'], $runList);
        $data['runList'] = $runList;
        [$data['replayed'], $data['saved'], $data['replayedRemote']] = $this->replayedAgainst($graph, $this->branch, $runList);

        if ($request->explain || $request->dryRun) {
            $baselineLine = $this->baseline === null ? null : BaselineResolver::describe($this->baseline, $this->branch);

            if ($baselineLine !== null) {
                fwrite(STDOUT, $baselineLine . PHP_EOL);
            }

            foreach ((new ExplainFormatter())->lines($data['list'], $runList) as $line) {
                fwrite(STDOUT, $line . PHP_EOL);
            }
        }

        if ($request->dryRun) {
            $summary = new DryRunSummary(
                count($runList),
                $data['affected'],
                $data['uncached'],
                $data['quarantined'],
                $data['replayed'],
            );

            fwrite(STDOUT, $summary->format() . PHP_EOL);

            return 0;
        }

        if ($runList === []) {
            if ($changed !== []) {
                if ($this->persist && (! $this->ciMode || $request->allowCiBaseline)) {
                    (new GraphUpdater($graph, $root, new ContentKey($root), $this->quarantine, $this->staticEdges))
                        ->finalizeBaseline($this->branch, $this->head, $this->git->branchNames());
                }

                $this->store->save($graph);
                $this->quarantine->save($this->stateDir);
            }

            // Nothing executed: affected/uncached/quarantined (test counts, see
            // executeReplay()) are necessarily all zero too.
            $this->printSummary(0, 0, 0, $data['replayed'], $data['replayedRemote'], 0, $data['saved'], true);

            if ($request->logJunit !== null) {
                $merged = (new JUnitMerger())->merge(null, $graph->results($this->branch), $root);
                @file_put_contents($request->logJunit, $merged);
            }

            $this->finalizeCoveragePhpWithoutARun($request, $graph, $root);

            if ($this->persist) {
                $dirty = $changedFiles->since($this->head) ?? [];
                (new LastRunTree($this->branch, $this->head, $changedFiles->snapshotTree($dirty), time()))->save($this->stateDir);
            }

            return 0;
        }

        return $this->executeReplay($request, $graph, $root, $runList, $data, $changedFiles);
    }

    /**
     * @param list<string> $runList
     * @param array{list: RunList, runList: list<string>, affected: int, uncached: int, quarantined: int, replayed: int, saved: float} $data
     */
    private function executeReplay(
        RunRequest $request,
        Graph $graph,
        string $root,
        array $runList,
        array $data,
        ChangedFiles $changedFiles,
    ): int {
        $recordsEdges = $this->driverName !== 'none';
        $mode = $recordsEdges ? 'record-subset' : 'results-only';

        $xml = (new ConfigurationWriter())->write($this->configFile, $runList, $root);
        $this->generatedXml = $xml;

        $runId = self::newRunId();
        $this->runDir = $this->stateDir . '/runs/' . $runId;

        $phpunitArgsForRun = $request->phpunitArgs;
        $junitPath = null;

        if ($request->logJunit !== null) {
            $junitPath = $this->runDir . '/junit.xml';
            $phpunitArgsForRun[] = '--log-junit';
            $phpunitArgsForRun[] = $junitPath;
        }

        [$phpunitArgsForRun, $coveragePhpTarget] = $this->redirectCoveragePhp($phpunitArgsForRun, $root);

        $exitCode = $this->runPhpunit(
            $xml,
            $recordsEdges ? $this->iniFlags : [],
            $phpunitArgsForRun,
            $recordsEdges,
            $this->baseEnv($mode, $runId),
            $root,
        );

        $partial = RunPartial::load($this->runDir);

        if ($partial === null) {
            return $exitCode;
        }

        if (LaravelDetector::enabled($root, $this->config)) {
            $partial = LaravelIntegration::augment($partial, $root);
        }

        $complete = ! (bool) ($partial->meta['truncated'] ?? false) && in_array($exitCode, [0, 1], true);

        $updater = new GraphUpdater($graph, $root, new ContentKey($root), $this->quarantine, $this->staticEdges);
        $applied = $updater->apply($partial, $this->branch, recordsEdges: $recordsEdges, complete: $complete);
        $this->quarantine->save($this->stateDir);

        if ($this->fingerprintDrifted($partial)) {
            return $exitCode;
        }

        $this->persistAfterRun($updater, $complete, $changedFiles);
        $this->pushAfterRun($graph, $applied['touched'], $complete);

        $replayed = [];
        $replayedRemote = 0;

        foreach ($graph->results($this->branch) as $testId => $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '' && ! in_array($file, $runList, true)) {
                $replayed[$testId] = $result;

                if (isset($this->remoteTestIds[$testId])) {
                    $replayedRemote++;
                }
            }
        }

        if ($request->logJunit !== null) {
            $realJunit = ($junitPath !== null && is_file($junitPath)) ? @file_get_contents($junitPath) : null;
            $merged = (new JUnitMerger())->merge($realJunit === false ? null : $realJunit, $replayed, $root);
            @file_put_contents($request->logJunit, $merged);
        }

        $savedSeconds = 0.0;
        $replayedFiles = [];

        foreach ($replayed as $result) {
            $savedSeconds += $result['time'];
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $replayedFiles[$file] = true;
            }
        }

        if ($coveragePhpTarget !== null) {
            $this->finalizeCoveragePhp($this->runDir . '/coverage.php', $coveragePhpTarget, $root, array_keys($replayedFiles));
        }

        $executed = self::classifyExecuted($data['list'], $partial->results);

        $this->printSummary(
            count($partial->results),
            $executed['affected'],
            $executed['uncached'],
            count($replayed),
            $replayedRemote,
            $executed['quarantined'],
            $savedSeconds,
            $exitCode === 0,
        );

        return $exitCode;
    }

    /**
     * `affected`/`uncached`/`quarantined` here count test FILES, computed before anything
     * has run — the only thing `--dry-run` (Report\DryRunSummary) can report. The real
     * run's own Summary counters (docs/INTERNALS.md "Summary counters") are test counts,
     * classified per executed result afterwards by {@see self::classifyExecuted()}.
     *
     * @param list<string> $changed
     * @return array{list: RunList, runList: list<string>, affected: int, uncached: int, quarantined: int, replayed: int, replayedRemote: int, saved: float}
     */
    private function computeRunList(Graph $graph, array $changed, string $branch, string $root): array
    {
        $builder = new RunListBuilder(
            $graph,
            $this->testPaths,
            $this->watch,
            $this->reader,
            new Policy($graph, $this->config, $this->quarantine, $root),
            $root,
            LaravelIntegration::rulesFor($graph, $root, $this->config),
            $this->config->staticDeclarationEdges,
        );

        $list = $builder->build($changed, $branch);

        $affectedFiles = $list->selection->testFiles();
        $uncachedSet = array_diff(array_unique(array_merge($list->unknown, $list->rerun)), $affectedFiles);

        $runList = $list->files();

        if ($list->selection->sourcePhpChanged && $this->driverName === 'none') {
            Warnings::warn('no coverage driver: re-running the whole suite in results-only mode (cannot refresh dependency edges)');
            $runList = $builder->allTestFilesOnDisk();
        }

        [$replayed, $saved, $replayedRemote] = $this->replayedAgainst($graph, $branch, $runList);

        return [
            'list' => $list,
            'runList' => $runList,
            'affected' => count($affectedFiles),
            'uncached' => count($uncachedSet),
            'quarantined' => count($list->quarantined),
            'replayed' => $replayed,
            'replayedRemote' => $replayedRemote,
            'saved' => $saved,
        ];
    }

    /**
     * What this pass will replay rather than execute, off the same {@see ReplaySet} that
     * decides `verify`'s `would replay` figure — the two numbers describe the same thing and
     * must not be able to disagree ({@see self::replaySetBeforeVerify()}, SPEC.md §12.2).
     *
     * @param list<string> $runList
     * @return array{0: int, 1: float, 2: int} replayed tests, seconds saved, of which remote
     */
    private function replayedAgainst(Graph $graph, string $branch, array $runList): array
    {
        $set = ReplaySet::against($graph, $branch, $runList);
        $remote = 0;

        foreach ($set->testIds() as $testId) {
            if (isset($this->remoteTestIds[$testId])) {
                $remote++;
            }
        }

        return [$set->count(), $set->savedSeconds(), $remote];
    }

    /**
     * SPEC.md §9 / docs/INTERNALS.md "Pipeline changes": every test file the run list holds
     * *only* because it is affected gets its content key recomputed here (from the old
     * edges, deliberately: same test file plus same dependency contents means the same key,
     * whoever recorded it) and looked up in the remote. A hit means another machine already
     * ran exactly this content — the file leaves the run list and its results are merged in
     * as replayed-remote.
     *
     * Files in the run list for any other reason are left alone: an unknown file has no
     * edges to key on, a `Rerun` file holds a result SPEC §6.2 says must be re-run
     * regardless of the cache, and a quarantined/non-cacheable file must never be replayed
     * from anywhere. An object holding a result that would itself force a re-run is skipped
     * for the same reason.
     *
     * @param list<string> $runList
     * @return list<string> the run list without the files served from the remote
     */
    private function replayFromRemote(Graph $graph, RunList $list, array $runList): array
    {
        $objects = $this->objects;

        if ($objects === null || $runList === []) {
            return $runList;
        }

        $contentKey = new ContentKey($this->root ?? '');
        $skip = array_fill_keys([...$list->unknown, ...$list->rerun, ...$list->quarantined], true);
        $kept = [];
        $files = 0;

        foreach ($runList as $file) {
            if (isset($skip[$file]) || ! $list->selection->has($file) || $graph->isNotCacheable($file)) {
                $kept[] = $file;

                continue;
            }

            $key = $contentKey->forTestFile($graph, $file);
            $object = $key === null ? null : $objects->object($key);

            if ($key === null || $object === null || $this->holdsARerun($object['results'])) {
                $kept[] = $file;

                continue;
            }

            foreach ($object['results'] as $testId => $result) {
                $result['file'] = $file;
                $result['key'] = $key;
                $graph->setResult($this->branch, $testId, $result);
                $this->remoteTestIds[$testId] = true;
            }

            $files++;
            Warnings::debug('remote: replayed ' . $file . ' from objects/*/' . $key . '.json');
        }

        if ($files > 0) {
            Warnings::debug('remote: ' . $files . ' test file(s) replayed from the remote');
        }

        return $kept;
    }

    /**
     * A cached failure/error always re-runs (SPEC.md §6.2), so an object carrying one is no
     * use: replaying it would hide the very result the rule exists to re-check.
     *
     * @param array<string, array{status:int, message:string, time:float, assertions:int, file?:string, key?:string}> $results
     */
    private function holdsARerun(array $results): bool
    {
        foreach ($results as $result) {
            if ($this->reader->shouldRerun($result['status'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * docs/INTERNALS.md "Pipeline changes": publish one `objects/<shard>/<k>.json` per test
     * file this pass executed, and the whole graph when `remote_push` is `all` and the pass
     * earned a baseline. `remote_push => 'off'` makes the remote pull-only; `CI=true`
     * publishes objects but never a graph unless `--allow-ci-baseline` says so (SPEC §12.1).
     *
     * @param list<string> $executedTestFiles project-relative, from GraphUpdater::apply()
     */
    private function pushAfterRun(Graph $graph, array $executedTestFiles, bool $complete): void
    {
        $objects = $this->objects;

        if ($objects === null || $this->config->remotePush === 'off') {
            return;
        }

        $contentKey = new ContentKey($this->root ?? '');
        $own = $graph->ownResults($this->branch);
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

        if ($this->config->remotePush !== 'all' || ! $complete || ! $this->persist) {
            return;
        }

        if ($this->ciMode && ! $this->request->allowCiBaseline) {
            Warnings::debug('remote: CI detected, the branch graph was not published (pass --allow-ci-baseline to override)');

            return;
        }

        $this->pushGraph($graph, $objects);
    }

    /** The one place a whole branch graph goes to the remote, shared with `push --graph`. */
    private function pushGraph(Graph $graph, ObjectStore $objects): bool
    {
        $body = $graph->encode();

        if ($body === null) {
            return false;
        }

        return $objects->putGraph($this->branch, $body);
    }

    /**
     * Report\Summary's own affected/uncached/quarantined counters (docs/INTERNALS.md
     * "Summary counters", SPEC.md §11): classifies each executed test result by its
     * file's {@see RunList::primaryReasonFor()}, so `executed === affected + uncached +
     * quarantined` always holds for a real (non-dry-run) pass.
     *
     * @param array<string, array{status:int, message:string, time:float, assertions:int, file?:string}> $results
     * @return array{affected: int, uncached: int, quarantined: int}
     */
    private static function classifyExecuted(RunList $list, array $results): array
    {
        $affected = 0;
        $uncached = 0;
        $quarantined = 0;

        foreach ($results as $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            match ($list->primaryReasonFor($file)) {
                'affected' => $affected++,
                'uncached' => $uncached++,
                default => $quarantined++,
            };
        }

        return ['affected' => $affected, 'uncached' => $uncached, 'quarantined' => $quarantined];
    }

    private function printSummary(int $executed, int $affected, int $uncached, int $replayed, int $replayedRemote, int $quarantined, float $saved, bool $success): void
    {
        $summary = new Summary(
            $executed,
            $affected,
            $uncached,
            $replayed,
            $replayedRemote,
            $quarantined,
            $this->persist ? $this->branch : null,
            $this->persist ? $this->head : null,
            $saved,
            $success,
        );

        fwrite(STDOUT, $summary->format() . PHP_EOL);
    }

    /**
     * Bug fix (--dry-run must never launch PHPUnit): the "no baseline" and "degraded"
     * cases have no {@see RunList} to classify, so {@see DryRunSummary}'s numeric
     * affected/uncached/quarantined buckets don't apply — inventing fake counts for them
     * would misrepresent the plan rather than describe it. {@see ExplainFormatter}
     * doesn't fit either, for the same reason: it also renders a RunList. A plain
     * sentence under the same "Replay" label keeps the tool's visual convention without
     * bending either format to a shape it wasn't built for.
     */
    private static function dryRunLine(string $message): string
    {
        return Summary::label(false) . '  ' . $message;
    }

    private function printRecordSummary(RunPartial $partial): void
    {
        $graph = $this->graph;

        if ($graph === null) {
            return;
        }

        $stats = $graph->stats();
        $graphBytes = @filesize($this->store->path());

        $summary = Summary::recorded(
            count($partial->results),
            $stats['test_files'],
            $stats['files'],
            $stats['edges'],
            $graphBytes !== false ? $graphBytes : 0,
            microtime(true) - $this->startedAt,
            $this->persist ? $this->branch : null,
            $this->persist ? $this->head : null,
        );

        fwrite(STDOUT, $summary->format() . PHP_EOL);
    }

    /** True after a complete pass whose post-run fingerprint no longer matches the pre-run one. */
    private function fingerprintDrifted(RunPartial $partial): bool
    {
        $post = self::stringKeyedArray($partial->meta['fingerprint'] ?? null);

        if ($post === []) {
            return false;
        }

        $drift = Fingerprint::structuralDrift($this->fingerprint, $post);

        if ($drift === []) {
            return false;
        }

        Warnings::warn(sprintf('project structure changed during the run (%s): discarding the recorded graph', implode(', ', $drift)));

        return true;
    }

    /**
     * SPEC.md §3.2 last paragraph, docs/INTERNALS.md "CoverageMerger": when `--coverage-php`
     * is among the user's PHPUnit args, PHPUnit's own output is redirected to a run-scoped
     * path so {@see self::finalizeCoveragePhp()} can fold in the snapshots of replayed test
     * files before anything reaches the path the user actually asked for.
     *
     * @param list<string> $phpunitArgs
     * @return array{0: list<string>, 1: ?string} the (possibly rewritten) args, and the
     *         resolved absolute target path, or null when `--coverage-php` was not requested
     */
    private function redirectCoveragePhp(array $phpunitArgs, string $root): array
    {
        $requested = CoveragePhpOption::path($phpunitArgs);

        if ($requested === null) {
            return [$phpunitArgs, null];
        }

        $target = Paths::isAbsolute($requested) ? $requested : Paths::join($root, $requested);
        $runCoveragePhp = ($this->runDir ?? $root) . '/coverage.php';

        return [CoveragePhpOption::withPath($phpunitArgs, $runCoveragePhp), $target];
    }

    /**
     * Folds the snapshots of every replayed test file (SPEC.md §3.2 last paragraph,
     * Record\CoverageSnapshots) into `$runCoveragePhp` (this pass's own PHPUnit output, or a
     * synthetic empty one from {@see self::finalizeCoveragePhpWithoutARun()}) and writes the
     * result to the path the user actually asked for.
     *
     * @param list<string> $replayedFiles project-relative test files served from cache this pass
     */
    private function finalizeCoveragePhp(string $runCoveragePhp, string $target, string $root, array $replayedFiles): void
    {
        if (! is_file($runCoveragePhp)) {
            Warnings::warn('--coverage-php requested but PHPUnit produced no coverage; nothing written to ' . $target);

            return;
        }

        $snapshotPaths = [];

        foreach ($replayedFiles as $relative) {
            $key = ContentHash::of(Paths::join($root, $relative));

            if ($key === null) {
                continue;
            }

            $path = $this->stateDir . '/coverage/' . $key . '.cov';

            if (is_file($path)) {
                $snapshotPaths[] = $path;
            }
        }

        if (! CoverageMerger::merge($runCoveragePhp, $snapshotPaths, $target)) {
            Warnings::warn('could not write merged coverage to ' . $target);
        }
    }

    /**
     * The `runList === []` branch of {@see self::runReplay()} never launches PHPUnit at all:
     * when `--coverage-php` was requested anyway, an empty `CodeCoverage` scoped to the
     * configuration's `<source>` directories stands in for "this pass's own run", so every
     * test file being served from cache (every one of them, since nothing executed) still
     * has its snapshot folded in.
     */
    private function finalizeCoveragePhpWithoutARun(RunRequest $request, Graph $graph, string $root): void
    {
        $requested = CoveragePhpOption::path($request->phpunitArgs);

        if ($requested === null) {
            return;
        }

        $target = Paths::isAbsolute($requested) ? $requested : Paths::join($root, $requested);

        $runId = self::newRunId();
        $this->runDir = $this->stateDir . '/runs/' . $runId;
        $emptyRunCoveragePhp = $this->runDir . '/coverage.php';

        if (! CoverageMerger::writeEmptyRun($emptyRunCoveragePhp, $this->reader)) {
            Warnings::warn('could not build an empty coverage baseline for --coverage-php=' . $target);

            return;
        }

        $replayedFiles = [];

        foreach ($graph->results($this->branch) as $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $replayedFiles[$file] = true;
            }
        }

        $this->finalizeCoveragePhp($emptyRunCoveragePhp, $target, $root, array_keys($replayedFiles));
    }

    /** Shared by runRecord() and executeReplay(): finalize the baseline (subject to CI rules) then always save the graph. */
    private function persistAfterRun(GraphUpdater $updater, bool $complete, ChangedFiles $changedFiles): void
    {
        if ($this->graph === null) {
            return;
        }

        (new BaselineWriter($this->store, $this->git, $changedFiles))
            ->commit($this->graph, $updater, $this->runContext(), $complete);
    }

    private function runContext(): RunContext
    {
        return new RunContext(
            $this->root ?? $this->request->cwd,
            $this->stateDir,
            $this->branch,
            $this->head,
            $this->defaultBranch,
            $this->persist,
            $this->ciMode,
            $this->request->allowCiBaseline,
        );
    }

    /**
     * The one branch that decides between {@see PhpunitProcess} and {@see ParatestProcess}
     * (SPEC.md §13, `--parallel`/`-p`): every call site that runs an instrumented pass —
     * `runResultsOnly()`, `runRecord()`, `verify()`, `executeReplay()` — hands off here
     * instead of constructing a process runner directly.
     *
     * Also the one place that decides whether Paratest gets Laravel's per-worker database
     * isolation wired up ({@see ParallelIsolation}). Three of the gate's four "no" answers
     * are silent, because the feature simply does not apply: non-Laravel project, config
     * opt-out, and Paratest missing (already warned about in {@see ParatestProcess::run()}).
     * The fourth warns from inside {@see ParallelIsolation::enabled()}: Laravel and Paratest
     * are both present, but `Illuminate\Testing\ParallelRunner` could not be resolved in the
     * project, so the run would otherwise proceed with exactly the behaviour this isolation
     * exists to prevent.
     *
     * A project that DOES pass the gate and still cannot resolve a Laravel application
     * ({@see ParallelIsolation::applicationResolvable()}) gets a warning here instead:
     * without that check, Paratest's own top-level process would crash outright
     * (`RuntimeException('Parallel Runner unable to resolve application.')`, thrown before a
     * single test runs) rather than degrading.
     *
     * Doc fix: this used to claim "every other call site in this class", which stopped
     * being true once {@see self::verify()} started routing through here too (it used to
     * construct a `PhpunitProcess` directly, silently ignoring `--parallel`) — but it was
     * already inaccurate before that fix for a different reason: {@see self::degrade()}
     * constructs a bare `PhpunitProcess` of its own, deliberately. Its fallback run is not
     * an instrumented pass at all — no coverage extension, no `--parallel`/Paratest, no
     * generated config — precisely because whatever made the wrapper degrade may be the
     * reason an instrumented run cannot be trusted; going through this method would wire
     * that fallback into the same machinery being degraded away from.
     *
     * @param list<string> $iniFlags
     * @param list<string> $phpunitArgs
     * @param array<string, string> $env
     */
    private function runPhpunit(
        ?string $configFile,
        array $iniFlags,
        array $phpunitArgs,
        bool $appendNoCoverage,
        array $env,
        string $cwd,
    ): int {
        if ($this->request->parallel === null) {
            return (new PhpunitProcess())->run($this->phpunitBin, $configFile, $iniFlags, $phpunitArgs, $appendNoCoverage, $env, $cwd);
        }

        $root = $this->root ?? $cwd;
        $paratestBin = $root . '/vendor/bin/paratest';

        $laravelParallelIsolation = false;

        if (ParallelIsolation::enabled($root, $this->config)) {
            if (ParallelIsolation::applicationResolvable($root)) {
                $laravelParallelIsolation = true;
            } else {
                Warnings::warn('Laravel detected but no bootstrap/app.php or Tests\\CreatesApplication was found; running --parallel without per-worker database isolation');
            }
        }

        return (new ParatestProcess())->run($paratestBin, $this->phpunitBin, $configFile, $iniFlags, $phpunitArgs, $appendNoCoverage, $env, $cwd, $this->request->parallel, $laravelParallelIsolation);
    }

    private function degrade(RunRequest $request, string $reason): int
    {
        Warnings::warn($reason);

        // Bug fix: degrade() is the single funnel every degrade case goes through
        // (environment resolution failures, an unexpected exception, no coverage driver
        // for `run`/`verify`) — one check here closes all of them at once instead of
        // sprinkling a dryRun check at each of the six call sites. A dry run that
        // degrades still owes the caller a plan, so it names both the reason and what
        // running for real would have done, then returns without ever constructing a
        // PhpunitProcess.
        if ($request->dryRun) {
            fwrite(STDOUT, self::dryRunLine('would run the full suite via plain phpunit (degraded: ' . $reason . ')') . PHP_EOL);

            return 0;
        }

        $base = $this->root ?? $request->cwd;
        $override = getenv('PHPUNIT_REPLAY_PHPUNIT_BIN');
        $bin = (is_string($override) && $override !== '') ? $override : $base . '/vendor/bin/phpunit';

        $exitCode = (new PhpunitProcess())->run($bin, null, [], $request->phpunitArgs, false, [], $request->cwd);

        // Bug fix: a real 9056-test suite hit a removed PHPUnit method mid-`record`,
        // degrade() caught it here, ran the full suite for real via the branch above,
        // and returned PHPUnit's own exit code (0, every test passed) — a `record` that
        // wrote no graph and no state directory at all, indistinguishable from a
        // successful one until someone thought to `ls` the state dir. `record`'s whole
        // job is the graph, not the tests' pass/fail (nothing gates merges on it the way
        // `run`'s exit code does — RecordCommand's own docblock: "what CI runs on main to
        // publish a fresh baseline"), so when it degrades with nothing to show for it,
        // that is `record` failing at its one job and must not read as green. `run`
        // degrading the same way is untouched: falling back to a plain suite is its
        // documented, legitimate behaviour (README "it can never break a test run"), and
        // its exit code is the actual pass/fail signal CI gates on — changing it would be
        // exactly the harm that rule exists to prevent. See {@see self::DEGRADED_RECORD_EXIT_CODE}.
        if ($request->record && $exitCode === 0) {
            Warnings::warn('record degraded and wrote no graph or state directory — the baseline was NOT refreshed; fix "' . $reason . '" and rerun');

            return self::DEGRADED_RECORD_EXIT_CODE;
        }

        return $exitCode;
    }

    /** @return array<string, string> */
    private function baseEnv(string $mode, string $runId): array
    {
        $env = [
            'PHPUNIT_REPLAY_MODE' => $mode,
            'PHPUNIT_REPLAY_STATE_DIR' => $this->stateDir,
            'PHPUNIT_REPLAY_RUN_ID' => $runId,
            'PHPUNIT_REPLAY_ROOT' => $this->root ?? $this->request->cwd,
        ];

        // The extension in the child process (and in every Paratest worker under it) has
        // to filter behavioural edges and compute the same structural fingerprint as this
        // wrapper, and it only ever reads env — Config::mergeEnv().
        if ($this->config->staticDeclarationEdges) {
            $env['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES'] = '1';
        }

        if (Warnings::debugEnabled()) {
            $env['PHPUNIT_REPLAY_DEBUG'] = '1';
        }

        return $env;
    }

    private static function newRunId(): string
    {
        return date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    /** @return array<string, mixed> */
    private static function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $out[$key] = $item;
            }
        }

        return $out;
    }

    /** Step 10: delete the generated xml and run partial dir, unless PHPUNIT_REPLAY_KEEP_RUN=1. */
    private function cleanup(): void
    {
        if (getenv('PHPUNIT_REPLAY_KEEP_RUN') === '1') {
            return;
        }

        if ($this->generatedXml !== null && is_file($this->generatedXml)) {
            @unlink($this->generatedXml);
        }

        if ($this->runDir !== null && is_dir($this->runDir)) {
            self::removeDirectory($this->runDir);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($dir);
    }
}
