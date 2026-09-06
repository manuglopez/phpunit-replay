<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

use FilesystemIterator;
use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Change\LastRunTree;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Report\JUnitMerger;
use Manuglopez\Replay\Report\Summary;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\Selector;
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
 * running `vendor/bin/phpunit` exactly as the user would have, returning its exit code.
 */
final class RunPipeline
{
    /** @var array<int, string> PHPUnit\Framework\TestStatus\TestStatus::asInt() names, SPEC §4.2 */
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

    private TestPaths $testPaths;

    private WatchPatterns $watch;

    /** @var array<string, mixed> */
    private array $fingerprint = [];

    private ?Graph $graph = null;

    private GraphStore $store;

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
        $this->stateDir = StateDirectory::resolve($config->stateDir, $root);

        $branch = $this->git->currentBranch();
        $this->persist = $branch !== null;
        $this->branch = $branch ?? 'HEAD';
        $this->defaultBranch = $config->defaultBranch ?? $this->git->defaultBranch() ?? 'main';
        $this->head = $this->git->currentSha();
        $this->ciMode = self::isCi();

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

        $exitCode = (new PhpunitProcess())->run(
            $this->phpunitBin,
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
                $updater = new GraphUpdater($this->graph, $this->root ?? '', new ContentKey($this->root ?? ''));
                $updater->apply($partial, $this->branch, recordsEdges: false, complete: false);
                $this->store->save($this->graph);
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
            Warnings::warn('the PHPUnit run produced no run partial; nothing recorded');

            return $exitCode;
        }

        $complete = ! (bool) ($partial->meta['truncated'] ?? false) && in_array($exitCode, [0, 1], true);

        $updater = new GraphUpdater($this->graph, $root, new ContentKey($root));
        $updater->apply($partial, $this->branch, recordsEdges: true, complete: $complete);

        if ($this->fingerprintDrifted($partial)) {
            return $exitCode;
        }

        $this->persistAfterRun($updater, $complete, new ChangedFiles($root, $this->git));
        $this->printRecordSummary($partial);

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
            $this->printExplain($data['selection'], $data['unknown'], $data['rerun'], $runList, $graph, $this->branch);
        }

        if ($request->dryRun) {
            $this->printSummary(0, $data['affected'], $data['uncached'], $data['replayed'], $data['saved'], true);

            return 0;
        }

        if ($runList === []) {
            if ($changed !== []) {
                if ($this->persist && (! $this->ciMode || $request->allowCiBaseline)) {
                    (new GraphUpdater($graph, $root, new ContentKey($root)))
                        ->finalizeBaseline($this->branch, $this->head, $this->git->branchNames());
                }

                $this->store->save($graph);
            }

            $this->printSummary(0, 0, 0, $data['replayed'], $data['saved'], true);

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
     * @param array{selection: Selection, unknown: list<string>, rerun: list<string>, runList: list<string>, affected: int, uncached: int, replayed: int, saved: float} $data
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

        $exitCode = (new PhpunitProcess())->run(
            $this->phpunitBin,
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

        $complete = ! (bool) ($partial->meta['truncated'] ?? false) && in_array($exitCode, [0, 1], true);

        $updater = new GraphUpdater($graph, $root, new ContentKey($root));
        $updater->apply($partial, $this->branch, recordsEdges: $recordsEdges, complete: $complete);

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

        $this->printSummary(
            count($partial->results),
            $data['affected'],
            $data['uncached'],
            count($replayed),
            $savedSeconds,
            $exitCode === 0,
        );

        return $exitCode;
    }

    /**
     * @param list<string> $changed
     * @return array{selection: Selection, unknown: list<string>, rerun: list<string>, runList: list<string>, affected: int, uncached: int, replayed: int, saved: float}
     */
    private function computeRunList(Graph $graph, array $changed, string $branch, string $root): array
    {
        $selector = Selector::default($graph, $this->testPaths, $this->watch, $root);
        $selection = $selector->affected($changed);

        $unknown = $this->unknownTestFiles($root, $graph);
        $rerun = $this->rerunFiles($graph, $branch, $root);

        $affectedFiles = $selection->testFiles();
        $uncachedSet = array_diff(array_unique(array_merge($unknown, $rerun)), $affectedFiles);

        $runList = array_values(array_unique(array_merge($affectedFiles, $unknown, $rerun)));
        sort($runList);

        if ($selection->sourcePhpChanged && $this->driverName === 'none') {
            Warnings::warn('no coverage driver: re-running the whole suite in results-only mode (cannot refresh dependency edges)');
            $runList = $this->allTestFilesOnDisk($root);
        }

        [$replayed, $saved] = $this->replayedAgainst($graph, $branch, $runList);

        return [
            'selection' => $selection,
            'unknown' => $unknown,
            'rerun' => $rerun,
            'runList' => $runList,
            'affected' => count($affectedFiles),
            'uncached' => count($uncachedSet),
            'replayed' => $replayed,
            'saved' => $saved,
        ];
    }

    /** @return list<string> */
    private function unknownTestFiles(string $root, Graph $graph): array
    {
        $unknown = [];

        foreach ($this->candidateTestFiles($root) as $rel) {
            if ($this->testPaths->isTestFile($rel) && ! $graph->knowsTest($rel)) {
                $unknown[] = $rel;
            }
        }

        sort($unknown);

        return $unknown;
    }

    /** @return list<string> */
    private function allTestFilesOnDisk(string $root): array
    {
        $all = [];

        foreach ($this->candidateTestFiles($root) as $rel) {
            if ($this->testPaths->isTestFile($rel)) {
                $all[] = $rel;
            }
        }

        sort($all);

        return $all;
    }

    /** @return list<string> project-relative candidates: everything under the known test directories, plus explicit files. */
    private function candidateTestFiles(string $root): array
    {
        $candidates = [];

        foreach ($this->testPaths->directories() as $dir) {
            $absoluteDir = Paths::join($root, $dir);

            if (! is_dir($absoluteDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteDir, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $fileInfo) {
                if (! $fileInfo instanceof SplFileInfo || ! $fileInfo->isFile()) {
                    continue;
                }

                $rel = Paths::relative($root, $fileInfo->getPathname());

                if ($rel !== null) {
                    $candidates[$rel] = true;
                }
            }
        }

        foreach ($this->testPaths->files() as $rel) {
            $candidates[$rel] = true;
        }

        return array_keys($candidates);
    }

    /** @return list<string> */
    private function rerunFiles(Graph $graph, string $branch, string $root): array
    {
        $files = [];

        foreach ($graph->results($branch) as $result) {
            $file = $result['file'] ?? null;

            if (! is_string($file) || $file === '') {
                continue;
            }

            if (! $this->reader->shouldRerun($result['status'])) {
                continue;
            }

            if (! is_file(Paths::join($root, $file))) {
                continue;
            }

            $files[$file] = true;
        }

        return array_keys($files);
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
     * @param list<string> $unknown
     * @param list<string> $rerun
     * @param list<string> $runList
     */
    private function printExplain(Selection $selection, array $unknown, array $rerun, array $runList, Graph $graph, string $branch): void
    {
        $unknownSet = array_fill_keys($unknown, true);
        $rerunSet = array_fill_keys($rerun, true);
        $reasons = $selection->reasons();

        $lines = [];

        foreach ($runList as $file) {
            $lines[$file] = $this->explainLine($file, $reasons[$file] ?? [], $unknownSet, $rerunSet, $graph, $branch);
        }

        ksort($lines);

        foreach ($lines as $line) {
            fwrite(STDOUT, $line . PHP_EOL);
        }
    }

    /**
     * @param list<Reason> $reasons
     * @param array<string, true> $unknownSet
     * @param array<string, true> $rerunSet
     */
    private function explainLine(string $file, array $reasons, array $unknownSet, array $rerunSet, Graph $graph, string $branch): string
    {
        if ($reasons !== []) {
            $reason = $reasons[0];

            return sprintf('%-40s ← %-8s %s', $file, $reason->rule, self::triggerText($reason->trigger, $reason->detail));
        }

        if (isset($unknownSet[$file])) {
            return sprintf('%-40s ← %-8s %s', $file, 'Uncached', 'new test file');
        }

        if (isset($rerunSet[$file])) {
            return sprintf('%-40s ← %-8s %s', $file, 'Rerun', $this->rerunStatusName($file, $graph, $branch));
        }

        return sprintf('%-40s ← %-8s %s', $file, '', '');
    }

    private static function triggerText(string $trigger, string $detail): string
    {
        return $detail === '' ? $trigger : sprintf('%s (%s)', $trigger, $detail);
    }

    private function rerunStatusName(string $file, Graph $graph, string $branch): string
    {
        foreach ($graph->results($branch) as $result) {
            if (($result['file'] ?? null) === $file && $this->reader->shouldRerun($result['status'])) {
                return self::STATUS_NAMES[$result['status']] ?? 'unknown';
            }
        }

        return 'unknown';
    }

    private function printSummary(int $executed, int $affected, int $uncached, int $replayed, float $saved, bool $success): void
    {
        $summary = new Summary(
            $executed,
            $affected,
            $uncached,
            $replayed,
            0,
            0,
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

        if ($complete) {
            if ($this->persist && (! $this->ciMode || $this->request->allowCiBaseline)) {
                $updater->finalizeBaseline($this->branch, $this->head, $this->git->branchNames());

                $dirty = $changedFiles->since($this->head) ?? [];
                (new LastRunTree($this->branch, $this->head, $changedFiles->snapshotTree($dirty), time()))->save($this->stateDir);
            } elseif ($this->persist && $this->ciMode && ! $this->request->allowCiBaseline) {
                Warnings::warn('CI detected: results saved locally but the baseline was not published (pass --allow-ci-baseline to override)');
            }
        }

        $this->store->save($this->graph);
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

    private static function isCi(): bool
    {
        $value = getenv('CI');

        if (! is_string($value) || $value === '') {
            return false;
        }

        return ! in_array(strtolower($value), ['0', 'false'], true);
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
