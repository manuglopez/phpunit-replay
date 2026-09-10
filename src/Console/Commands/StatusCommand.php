<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\BaselineResolver;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\StatusReport;
use Manuglopez\Replay\Hermeticity\DivergenceLog;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Record\DriverDetector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay status` (SPEC.md §11): a read-only snapshot of the cached graph.
 */
final class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Prints the cached graph state for this project.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd() ?: '.';
        $git = new Git($cwd);
        $root = $git->topLevel() ?? (realpath($cwd) ?: $cwd);
        $git = new Git($root);

        $config = Config::load($root);
        $stateDir = StateDirectory::resolve($config->stateDir, $root);

        $branch = $git->currentBranch();
        $defaultBranch = $config->defaultBranch ?? $git->defaultBranch() ?? 'main';
        $head = $git->currentSha();

        $loaded = DriverDetector::loadedExtension();
        $driverLine = match ($loaded) {
            'pcov' => 'pcov (loaded, enabled per run)',
            'xdebug' => 'xdebug',
            default => 'none',
        };

        $framework = self::detectFramework($root);
        // Same reason as PullCommand: without the flag, StatusReport::fingerprintLine()
        // diffs a fingerprint that never carries the key against a stored one that does,
        // and prints a permanent phantom "(drift)".
        $currentFingerprint = Fingerprint::compute($root, $loaded ?? 'none', $config->staticDeclarationEdges);
        $remoteLine = self::remoteLine($config, $stateDir);

        $quarantine = Quarantine::load($stateDir);
        $quarantine->setReleaseAfter($config->quarantineReleaseAfter);

        $quarantineEntries = [];

        foreach ($quarantine->all() as $testId => $entry) {
            if ($quarantine->isQuarantined($testId)) {
                $quarantineEntries[$testId] = $entry;
            }
        }

        ksort($quarantineEntries);

        $divergenceLog = DivergenceLog::read($stateDir);
        $divergence = $divergenceLog['runs'] > 0
            ? ['runs' => $divergenceLog['runs'], 'divergences' => count($divergenceLog['entries'])]
            : null;

        $store = new GraphStore($stateDir, $root);
        $graph = $store->load();

        // Local mirror reachability (docs/proposals/remote-layout.md §6): computed
        // regardless of whether a baseline exists yet, same as `remote:`/`push:` above —
        // no graph simply means nothing is addressable, which an all-zero line already
        // says correctly, with no special case needed.
        $reachable = $graph !== null ? array_fill_keys($graph->addressableKeys(), true) : [];
        $mirrorStats = ObjectStore::mirrorStats($stateDir, $reachable);

        if ($graph === null) {
            $output->writeln((new StatusReport(
                root: $root,
                branch: $branch,
                defaultBranch: $defaultBranch,
                headSha: $head,
                stateDir: $stateDir,
                driverLine: $driverLine,
                framework: $framework,
                hasBaseline: false,
                branches: [],
                files: 0,
                testFiles: 0,
                edges: 0,
                tables: 0,
                graphBytes: 0,
                graphFingerprint: null,
                currentFingerprint: $currentFingerprint,
                quarantined: count($quarantineEntries),
                quarantineEntries: $quarantineEntries,
                divergence: $divergence,
                remote: $remoteLine,
                remotePush: $config->remotePush,
                mirrorObjects: $mirrorStats['objects'],
                mirrorReachable: $mirrorStats['reachable'],
                mirrorReclaimableBytes: $mirrorStats['reclaimableBytes'],
            ))->lines());

            return Command::SUCCESS;
        }

        $graph->setDefaultBranch($defaultBranch);

        // DECISIONS.md D-039: which baseline this branch would actually diff against.
        // Resolved locally only — `status` never touches the network.
        $baseline = ($branch !== null && $head !== null)
            ? (new BaselineResolver($git, $graph, null, $config, $defaultBranch))->resolve($branch, $head)
            : null;

        $stats = $graph->stats();
        $branches = [];

        foreach ($graph->branches() as $name) {
            $branches[$name] = [
                'sha' => $graph->recordedSha($name),
                'complete' => $graph->isBaselineComplete($name),
                'results' => count($graph->ownResults($name)),
            ];
        }

        $graphBytes = @filesize($store->path());

        // Live check, not a stored counter (StatusReport::$excludedEdges docblock): how many
        // of the currently-recorded dependency edges point at a file `git check-ignore`
        // matches right now. Nonzero only for a graph recorded before this existed — the
        // structural fingerprint change forces exactly one fresh record, and nothing adds
        // such an edge afterwards.
        $excludedEdges = count($git->ignored($graph->files()) ?? []);

        $notCacheableFiles = 0;
        $notCacheableIds = 0;

        foreach ($graph->notCacheable() as $entry) {
            if (str_contains($entry, '::')) {
                $notCacheableIds++;
            } else {
                $notCacheableFiles++;
            }
        }

        $output->writeln((new StatusReport(
            root: $root,
            branch: $branch,
            defaultBranch: $defaultBranch,
            headSha: $head,
            stateDir: $stateDir,
            driverLine: $driverLine,
            framework: $framework,
            hasBaseline: true,
            branches: $branches,
            files: $stats['files'],
            testFiles: $stats['test_files'],
            edges: $stats['edges'],
            excludedEdges: $excludedEdges,
            tables: $stats['tables'],
            graphBytes: $graphBytes !== false ? $graphBytes : 0,
            graphFingerprint: $graph->fingerprint(),
            currentFingerprint: $currentFingerprint,
            quarantined: count($quarantineEntries),
            quarantineEntries: $quarantineEntries,
            notCacheableFiles: $notCacheableFiles,
            notCacheableIds: $notCacheableIds,
            divergence: $divergence,
            remote: $remoteLine,
            remotePush: $config->remotePush,
            mirrorObjects: $mirrorStats['objects'],
            mirrorReachable: $mirrorStats['reachable'],
            mirrorReclaimableBytes: $mirrorStats['reclaimableBytes'],
            baseline: $baseline,
        ))->lines());

        return Command::SUCCESS;
    }

    /** `none`, or `<backend name> <url>` with any embedded credentials masked (SPEC.md §9). */
    private static function remoteLine(Config $config, string $stateDir): string
    {
        if ($config->remote === null || trim($config->remote) === '') {
            return 'none';
        }

        return RemoteCacheFactory::fromConfig($config, $stateDir)->name() . ' ' . Config::maskRemote($config->remote);
    }

    private static function detectFramework(string $root): string
    {
        if (is_file($root . '/artisan')) {
            return 'laravel';
        }

        if (is_file($root . '/config/bundles.php')) {
            return 'symfony';
        }

        return 'plain';
    }
}
