<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

use FilesystemIterator;
use Manuglopez\Replay\Cache\BaselineWriter;
use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Cache\RunContext;
use Manuglopez\Replay\Cache\StateDirectory;
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
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Report\DryRunSummary;
use Manuglopez\Replay\Report\JUnitMerger;
use Manuglopez\Replay\Report\Summary;
use Manuglopez\Replay\Report\VerifySummary;
use Manuglopez\Replay\Select\RunList;
use Manuglopez\Replay\Select\RunListBuilder;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * SPEC.md §3.1 and docs/INTERNALS.md "Wrapper pipeline — detailed algorithm (phase 1,
 * filtered mode)": everything the CLI commands delegate to. Never throws: any failure
 * — anticipated (no git, no phpunit.xml, no coverage driver, ...) or not — degrades to
 * running `vendor/bin/phpunit` exactly as the user would have, returning its exit code.
 */
final class RunPipeline
{
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

    private ?Graph $graph = null;

    private GraphStore $store;

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
        $this->testPaths = TestPaths::fromConfiguration($configuration, $root);

        $this->watch = new WatchPatterns();
        $this->watch->useDefaults($root, $this->testPaths->directories());

        if ($config->watch !== []) {
            $this->watch->add($config->watch);
        }

        $this->fingerprint = Fingerprint::compute($root, $this->driverName);

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

        if ($this->graph === null) {
            return;
        }

        $structuralDrift = Fingerprint::structuralDrift($this->graph->fingerprint(), $this->fingerprint);

        if ($structuralDrift !== []) {
            Warnings::warn(sprintf('structural change (%s): recording a fresh baseline', implode(', ', $structuralDrift)));
            $this->graph = null;

            return;
        }

        $environmentalDrift = Fingerprint::environmentalDrift($this->graph->fingerprint(), $this->fingerprint);

        if ($environmentalDrift !== []) {
            Warnings::warn(sprintf('environment change (%s): cached results cleared', implode(', ', $environmentalDrift)));
            $this->graph->clearResults();
        }

        $this->graph->setDefaultBranch($this->defaultBranch);
    }

    /** Step 7: partial CLI selection (--filter, --group, --testsuite, an explicit path, ...). */
    private function runResultsOnly(RunRequest $request): int
    {
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

                $updater = new GraphUpdater($this->graph, $root, new ContentKey($root), $this->quarantine);
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

        $root = $this->root ?? $request->cwd;

        $this->graph = new Graph($root);
        $this->graph->setFingerprint($this->fingerprint);
        $this->graph->setDefaultBranch($this->defaultBranch);

        $xml = (new ConfigurationWriter())->withExtensionOnly($this->configFile);
        $this->generatedXml = $xml;

        $runId = self::newRunId();
        $this->runDir = $this->stateDir . '/runs/' . $runId;

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
            Warnings::warn('the PHPUnit run produced no run partial; nothing recorded');

            return $exitCode;
        }

        if (LaravelDetector::enabled($root, $this->config)) {
            $partial = LaravelIntegration::augment($partial, $root);
        }

        $complete = ! (bool) ($partial->meta['truncated'] ?? false) && in_array($exitCode, [0, 1], true);

        $updater = new GraphUpdater($this->graph, $root, new ContentKey($root), $this->quarantine);
        $updater->apply($partial, $this->branch, recordsEdges: true, complete: $complete);
        $this->quarantine->save($this->stateDir);

        if ($this->fingerprintDrifted($partial)) {
            return $exitCode;
        }

        $this->persistAfterRun($updater, $complete, new ChangedFiles($root, $this->git));
        $this->printRecordSummary($partial);

        return $exitCode;
    }

    /**
     * SPEC.md §12.2: full suite, `record` mode, graph kept (unlike {@see self::runRecord()},
     * which always starts from an empty one). Compares each new result against the one the
     * graph already had for the same content key, so a normal replay pass would have served
     * the cached result unchanged — any difference in result *class* is a divergence.
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

        $xml = (new ConfigurationWriter())->withExtensionOnly($this->configFile);
        $this->generatedXml = $xml;

        $runId = self::newRunId();
        $this->runDir = $this->stateDir . '/runs/' . $runId;

        $exitCode = (new PhpunitProcess())->run(
            $this->phpunitBin,
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

        $complete = ! (bool) ($partial->meta['truncated'] ?? false) && in_array($exitCode, [0, 1], true);

        // No quarantine passed here: divergences are detected explicitly below (reason
        // 'divergence', not the generic 'flip' GraphUpdater's own detection would use).
        $updater = new GraphUpdater($graph, $root, new ContentKey($root));
        $updater->apply($partial, $this->branch, recordsEdges: true, complete: $complete);

        if ($this->fingerprintDrifted($partial)) {
            return $exitCode;
        }

        $policy = new Policy($graph, $this->config, $this->quarantine, $root);

        $wouldReplay = 0;
        $divergenceEntries = [];

        foreach ($graph->results($this->branch) as $testId => $new) {
            $old = $oldResults[$testId] ?? null;

            if ($old === null) {
                continue;
            }

            $oldKey = $old['key'] ?? null;
            $newKey = $new['key'] ?? null;

            if ($oldKey === null || $newKey === null || $oldKey !== $newKey) {
                continue;
            }

            $file = $new['file'] ?? '';
            $excluded = $this->reader->shouldRerun($old['status']) || $file === '' || ! $policy->cacheable($file, $testId);

            if (! $excluded) {
                $wouldReplay++;
            }

            $oldClass = GraphUpdater::statusClass($old['status']);

            if ($oldClass === 'fail') {
                // A cached failure/error always reruns unconditionally regardless of the
                // cache (SPEC §6.2), so it was never really "replayed" in the first
                // place: recovering from one is not a divergence (GraphUpdater::detectFlip()
                // excludes the same transition for the same reason).
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
            $lifetime['divergences'],
            $lifetime['runs'],
            $exitCode === 0 && $divergenceEntries === [],
        );

        fwrite(STDOUT, $summary->format() . PHP_EOL);

        return $exitCode;
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

        $sha = $graph->recordedSha($this->branch);

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

        if ($request->explain || $request->dryRun) {
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
                    (new GraphUpdater($graph, $root, new ContentKey($root), $this->quarantine))
                        ->finalizeBaseline($this->branch, $this->head, $this->git->branchNames());
                }

                $this->store->save($graph);
                $this->quarantine->save($this->stateDir);
            }

            // Nothing executed: affected/uncached/quarantined (test counts, see
            // executeReplay()) are necessarily all zero too.
            $this->printSummary(0, 0, 0, $data['replayed'], 0, $data['saved'], true);

            if ($request->logJunit !== null) {
                $merged = (new JUnitMerger())->merge(null, $graph->results($this->branch), $root);
                @file_put_contents($request->logJunit, $merged);
            }

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

        $updater = new GraphUpdater($graph, $root, new ContentKey($root), $this->quarantine);
        $updater->apply($partial, $this->branch, recordsEdges: $recordsEdges, complete: $complete);
        $this->quarantine->save($this->stateDir);

        if ($this->fingerprintDrifted($partial)) {
            return $exitCode;
        }

        $this->persistAfterRun($updater, $complete, $changedFiles);

        $replayed = [];

        foreach ($graph->results($this->branch) as $testId => $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '' && ! in_array($file, $runList, true)) {
                $replayed[$testId] = $result;
            }
        }

        if ($request->logJunit !== null) {
            $realJunit = ($junitPath !== null && is_file($junitPath)) ? @file_get_contents($junitPath) : null;
            $merged = (new JUnitMerger())->merge($realJunit === false ? null : $realJunit, $replayed, $root);
            @file_put_contents($request->logJunit, $merged);
        }

        $savedSeconds = 0.0;

        foreach ($replayed as $result) {
            $savedSeconds += $result['time'];
        }

        $executed = self::classifyExecuted($data['list'], $partial->results);

        $this->printSummary(
            count($partial->results),
            $executed['affected'],
            $executed['uncached'],
            count($replayed),
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
     * @return array{list: RunList, runList: list<string>, affected: int, uncached: int, quarantined: int, replayed: int, saved: float}
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
        );

        $list = $builder->build($changed, $branch);

        $affectedFiles = $list->selection->testFiles();
        $uncachedSet = array_diff(array_unique(array_merge($list->unknown, $list->rerun)), $affectedFiles);

        $runList = $list->files();

        if ($list->selection->sourcePhpChanged && $this->driverName === 'none') {
            Warnings::warn('no coverage driver: re-running the whole suite in results-only mode (cannot refresh dependency edges)');
            $runList = $builder->allTestFilesOnDisk();
        }

        [$replayed, $saved] = $this->replayedAgainst($graph, $branch, $runList);

        return [
            'list' => $list,
            'runList' => $runList,
            'affected' => count($affectedFiles),
            'uncached' => count($uncachedSet),
            'quarantined' => count($list->quarantined),
            'replayed' => $replayed,
            'saved' => $saved,
        ];
    }

    /**
     * @param list<string> $runList
     * @return array{0: int, 1: float}
     */
    private function replayedAgainst(Graph $graph, string $branch, array $runList): array
    {
        $inRunList = array_fill_keys($runList, true);
        $count = 0;
        $saved = 0.0;

        foreach ($graph->results($branch) as $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '' && ! isset($inRunList[$file])) {
                $count++;
                $saved += $result['time'];
            }
        }

        return [$count, $saved];
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

    private function printSummary(int $executed, int $affected, int $uncached, int $replayed, int $quarantined, float $saved, bool $success): void
    {
        $summary = new Summary(
            $executed,
            $affected,
            $uncached,
            $replayed,
            0,
            $quarantined,
            $this->persist ? $this->branch : null,
            $this->persist ? $this->head : null,
            $saved,
            $success,
        );

        fwrite(STDOUT, $summary->format() . PHP_EOL);
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
     * (SPEC.md §13, `--parallel`/`-p`): every other call site in this class hands off here
     * instead of constructing a process runner directly.
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

        $paratestBin = ($this->root ?? $cwd) . '/vendor/bin/paratest';

        return (new ParatestProcess())->run($paratestBin, $this->phpunitBin, $configFile, $iniFlags, $phpunitArgs, $appendNoCoverage, $env, $cwd, $this->request->parallel);
    }

    private function degrade(RunRequest $request, string $reason): int
    {
        Warnings::warn($reason);

        $base = $this->root ?? $request->cwd;
        $override = getenv('PHPUNIT_REPLAY_PHPUNIT_BIN');
        $bin = (is_string($override) && $override !== '') ? $override : $base . '/vendor/bin/phpunit';

        return (new PhpunitProcess())->run($bin, null, [], $request->phpunitArgs, false, [], $request->cwd);
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
