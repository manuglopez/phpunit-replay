<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use DateTimeImmutable;
use FilesystemIterator;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\Remote\GitRemoteCache;
use Manuglopez\Replay\Cache\Remote\RemoteCache;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Support\Json;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
            ->addOption('remote', null, InputOption::VALUE_NONE, 'Garbage-collects the configured remote cache instead of the local graph.')
            ->addOption('keep-months', null, InputOption::VALUE_REQUIRED, 'Months of object shards to keep with --remote.', '3')
            ->addOption('squash', null, InputOption::VALUE_NONE, 'With --remote (git backend only): rewrite the remote branch as one orphan commit.')
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

        if ($input->getOption('remote') === true) {
            return self::pruneRemote($config, $stateDir, $input, $output);
        }

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

    /**
     * `prune --remote [--keep-months=N] [--squash]` (docs/INTERNALS.md "GitRemoteCache —
     * automatic maintenance"): deletes object shards older than N months (default 3) except
     * objects still referenced from a `graph/**` file, then `--squash` (git backend only)
     * rewrites the remote branch as a single orphan commit.
     */
    private static function pruneRemote(Config $config, string $stateDir, InputInterface $input, OutputInterface $output): int
    {
        $url = $config->remote;

        if ($url === null || $url === '') {
            $output->writeln('prune --remote: no remote configured');

            return Command::SUCCESS;
        }

        $cache = self::isGitRemote($url)
            ? GitRemoteCache::fromConfig($config, $stateDir)
            : self::buildFilesystemBackend($config, $stateDir, $output);

        if ($cache === null) {
            return Command::SUCCESS;
        }

        $keepMonthsOption = $input->getOption('keep-months');
        $keepMonths = max(1, is_numeric($keepMonthsOption) ? (int) $keepMonthsOption : 3);
        $squash = $input->getOption('squash') === true;

        $cache->begin();

        $cutoff = (new DateTimeImmutable('now'))->modify(sprintf('-%d months', $keepMonths))->format('Y-m');

        $referenced = [];

        foreach ($cache->keys('graph/') as $graphKey) {
            $body = $cache->get($graphKey);

            if ($body === null) {
                continue;
            }

            $decoded = Json::decodeArray($body);

            if ($decoded === null) {
                continue;
            }

            foreach (self::collectReferencedKeys($decoded) as $k) {
                $referenced[$k] = true;
            }
        }

        $removed = 0;
        $kept = 0;

        foreach ($cache->keys('objects/') as $objectKey) {
            if (preg_match('#^objects/(\d{4}-\d{2})/([^/]+)\.json$#', $objectKey, $matches) !== 1) {
                continue;
            }

            if ($matches[1] >= $cutoff) {
                continue;
            }

            if (isset($referenced[$matches[2]])) {
                $kept++;

                continue;
            }

            if ($cache->delete($objectKey)) {
                $removed++;
            }
        }

        $cache->end();

        $output->writeln(sprintf('remote prune: removed %d object(s), kept %d referenced object(s)', $removed, $kept));

        $error = $cache->lastError();

        if ($error !== null) {
            $output->writeln('remote prune warning: ' . $error);
        }

        if ($squash) {
            if (! $cache instanceof GitRemoteCache) {
                $output->writeln('remote prune: --squash is only supported by the git backend');
            } elseif ($cache->squash()) {
                $output->writeln('remote prune: squashed remote history');
            } else {
                $squashError = $cache->lastError();
                $output->writeln('remote prune: squash failed' . ($squashError !== null ? ' (' . $squashError . ')' : ''));
            }
        }

        return Command::SUCCESS;
    }

    private static function isGitRemote(string $url): bool
    {
        if (str_starts_with($url, 'git+') || str_starts_with($url, 'ssh://')) {
            return true;
        }

        if (str_ends_with($url, '.git')) {
            return true;
        }

        return preg_match('#^[\w.\-]+@[\w.\-]+:#', $url) === 1;
    }

    private static function buildFilesystemBackend(Config $config, string $stateDir, OutputInterface $output): ?RemoteCache
    {
        $class = '\\Manuglopez\\Replay\\Cache\\Remote\\FilesystemRemoteCache';

        if (! class_exists($class)) {
            $output->writeln('prune --remote needs the git or file backend');

            return null;
        }

        try {
            $cache = $class::fromConfig($config, $stateDir);
        } catch (Throwable) {
            $output->writeln('prune --remote needs the git or file backend');

            return null;
        }

        return $cache instanceof RemoteCache ? $cache : null;
    }

    /**
     * Recursively collects every string value stored under a `"k"` key anywhere in a decoded
     * graph JSON structure (docs/INTERNALS.md: baselines.<branch>.results.<testId>.k).
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function collectReferencedKeys(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if ($key === 'k' && is_string($value)) {
                $out[$value] = true;
            }

            if (is_array($value)) {
                foreach (self::collectReferencedKeys($value) as $nested) {
                    $out[$nested] = true;
                }
            }
        }

        return array_keys($out);
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
