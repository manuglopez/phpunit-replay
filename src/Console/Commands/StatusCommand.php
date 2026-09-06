<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\StatusReport;
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
        $currentFingerprint = Fingerprint::compute($root, $loaded ?? 'none');

        $store = new GraphStore($stateDir, $root);
        $graph = $store->load();

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
                graphBytes: 0,
                graphFingerprint: null,
                currentFingerprint: $currentFingerprint,
                quarantined: 0,
            ))->lines());

            return Command::SUCCESS;
        }

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
            graphBytes: $graphBytes !== false ? $graphBytes : 0,
            graphFingerprint: $graph->fingerprint(),
            currentFingerprint: $currentFingerprint,
            quarantined: 0,
        ))->lines());

        return Command::SUCCESS;
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
