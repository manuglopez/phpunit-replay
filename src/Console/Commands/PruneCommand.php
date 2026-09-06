<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use FilesystemIterator;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Quarantine;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay prune` (SPEC.md §11): drops stale state without touching a live pass.
 */
final class PruneCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('prune')
            ->setDescription('Prunes stale graph state: quarantine, deleted branches, deleted test files, or everything.')
            ->addOption('flaky', null, InputOption::VALUE_NONE, 'Clears the quarantine (flaky.json).')
            ->addOption('branches', null, InputOption::VALUE_NONE, 'Removes baselines of branches git no longer knows.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Deletes the whole state directory contents.')
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

        $flaky = $input->getOption('flaky') === true;
        $branches = $input->getOption('branches') === true;
        $all = $input->getOption('all') === true;

        if ($all) {
            foreach (['graph.json', 'flaky.json', 'last-run.json', 'divergence.json'] as $file) {
                @unlink(rtrim($stateDir, '/') . '/' . $file);
            }

            self::removeRunsDirectory($stateDir);

            $output->writeln('removed: graph.json, flaky.json, last-run.json, divergence.json, runs/');

            return Command::SUCCESS;
        }

        $noFlag = ! $flaky && ! $branches;
        $messages = [];

        if ($flaky) {
            $quarantine = Quarantine::load($stateDir);
            $count = count($quarantine->all());
            $quarantine->clear();
            $quarantine->save($stateDir);
            $messages[] = sprintf('quarantine cleared (%d entries)', $count);
        }

        $store = new GraphStore($stateDir, $root);
        $graph = $store->load();

        if ($graph !== null) {
            if ($noFlag) {
                $before = count($graph->allTestFiles());
                $graph->pruneMissingTestFiles();

                foreach ($graph->branches() as $branchName) {
                    $graph->pruneResultsForMissingFiles($branchName);
                }

                $after = count($graph->allTestFiles());
                $messages[] = sprintf('pruned %d deleted test file(s)', max(0, $before - $after));
            }

            if ($branches || $noFlag) {
                $keep = array_values(array_unique([
                    ...$git->branchNames(),
                    $config->defaultBranch ?? $git->defaultBranch() ?? 'main',
                    $graph->defaultBranch(),
                ]));

                $before = $graph->branches();
                $graph->pruneMissingBranches($keep);
                $removed = array_diff($before, $graph->branches());

                $messages[] = $removed === []
                    ? 'no branches to prune'
                    : 'pruned branches: ' . implode(', ', $removed);
            }

            $store->save($graph);
        }

        if ($messages === []) {
            $messages[] = 'nothing to prune';
        }

        foreach ($messages as $message) {
            $output->writeln($message);
        }

        return Command::SUCCESS;
    }

    private static function removeRunsDirectory(string $stateDir): void
    {
        $dir = rtrim($stateDir, '/') . '/runs';

        if (! is_dir($dir)) {
            return;
        }

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
