<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Record\DriverDetector;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay pull` (SPEC.md §9, §12): fetches the branch baseline from the remote —
 * this branch's own if it has one, otherwise the nearest configured candidate — and stores
 * it as this machine's local graph.
 *
 * A `run` with no local graph does this implicitly; the command exists to do it on demand
 * (before going offline, after a CI baseline job published a new graph, or to inspect what
 * the remote actually holds).
 */
final class PullCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('pull')
            ->setDescription('Fetches the branch baseline from the remote and stores it locally.')
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
        $remote = RemoteCacheFactory::fromConfig($config, $stateDir);

        if ($remote->name() === 'null') {
            $output->writeln('no remote configured (set `remote` in phpunit-replay.php or PHPUNIT_REPLAY_REMOTE)');

            return Command::FAILURE;
        }

        $defaultBranch = $config->defaultBranch ?? $git->defaultBranch() ?? 'main';
        $candidates = array_values(array_unique([
            ...($git->currentBranch() !== null ? [$git->currentBranch()] : []),
            ...$config->baselineCandidates($defaultBranch),
        ]));

        $remote->begin();

        try {
            $objects = new ObjectStore($remote, $stateDir, ProjectKey::shared($root));

            foreach ($candidates as $branch) {
                $graph = $objects->graphOf($branch, $root);

                if ($graph === null) {
                    continue;
                }

                $drift = Fingerprint::structuralDrift(
                    $graph->fingerprint(),
                    Fingerprint::compute($root, DriverDetector::loadedExtension() ?? 'none'),
                );

                if ($drift !== []) {
                    $output->writeln(sprintf(
                        'the remote %s baseline does not match this project (%s): not stored',
                        $branch,
                        implode(', ', $drift),
                    ));

                    return Command::FAILURE;
                }

                if (! (new GraphStore($stateDir, $root))->save($graph)) {
                    $output->writeln('could not write the graph to ' . $stateDir);

                    return Command::FAILURE;
                }

                $stats = $graph->stats();
                $output->writeln(sprintf(
                    'pulled the %s baseline: %d test files, %d results',
                    $branch,
                    $stats['test_files'],
                    $stats['results'],
                ));

                return Command::SUCCESS;
            }

            $output->writeln('the ' . $remote->name() . ' remote holds no baseline for ' . implode(', ', $candidates));
        } finally {
            $remote->end();
        }

        return Command::FAILURE;
    }
}
