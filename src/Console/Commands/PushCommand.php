<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay push [--graph]` (SPEC.md §9, §12): publishes to the configured remote
 * every object derivable from the local graph — one `objects/<shard>/<k>.json` per test
 * file that has cached results — and, with `--graph`, the branch baseline itself
 * (`graph/<project-key>/<branch>.json`), which is what the CI baseline job runs after a
 * `record --fresh`.
 *
 * A normal pass already publishes the objects of what it executed; this command exists to
 * publish a graph recorded before the remote was configured, to seed a new remote, and to
 * push a baseline from a job whose `remote_push` is the developer default.
 */
final class PushCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('push')
            ->setDescription('Publishes the cached objects (and, with --graph, the branch baseline) to the remote.')
            ->addOption('graph', null, InputOption::VALUE_NONE, 'Also publish the whole branch baseline (graph/<key>/<branch>.json).')
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

        $store = new GraphStore($stateDir, $root);
        $graph = $store->load();

        if ($graph === null) {
            $output->writeln('no baseline yet: run `phpunit-replay record` first');

            return Command::FAILURE;
        }

        $branch = $git->currentBranch() ?? $config->defaultBranch ?? $git->defaultBranch() ?? 'main';
        $graph->setDefaultBranch($config->defaultBranch ?? $git->defaultBranch() ?? 'main');

        $remote->begin();

        $objects = new ObjectStore($remote, $stateDir, ProjectKey::shared($root));
        $graphKey = null;
        $graphPutError = null;

        try {
            self::pushObjects($objects, $graph, $root, $branch);

            if ($input->getOption('graph') === true) {
                $body = $graph->encode();

                if ($body === null || ! $objects->putGraph($branch, $body)) {
                    // Captured now, before end() can overwrite lastError() with a (possibly
                    // unrelated) push failure of its own.
                    $graphPutError = $remote->lastError() ?? 'unknown error';
                } else {
                    $graphKey = ObjectStore::graphKey(ProjectKey::shared($root), $branch);
                }
            }
        } finally {
            // Bug fix: this used to print "pushed N object(s)" / "pushed the X baseline"
            // BEFORE end() ran — for the git backend, put()/putGraph() returning true only
            // means "staged in a local commit", not "pushed" (ObjectStore::putObject()'s
            // docblock, ObjectStore::confirmPublished()). A rejected push then printed
            // success twice, with the failure reported only on a third line below it — read,
            // in production, as "it worked". Both messages now wait for end() to actually
            // confirm the outcome, below.
            $remote->end();
        }

        if ($graphPutError !== null) {
            $output->writeln('could not publish the ' . $branch . ' baseline: ' . $graphPutError);

            return Command::FAILURE;
        }

        $error = $remote->lastError();

        if ($error !== null) {
            $output->writeln('remote (' . $remote->name() . '): ' . $error);

            return Command::FAILURE;
        }

        // Only now, after end() has confirmed the push, are these objects (and the graph, if
        // requested) durably in the remote. confirmPublished() is itself a no-op whenever
        // lastError() is non-null, so $pushed always reflects what actually landed.
        $pushed = $objects->confirmPublished();
        $output->writeln(sprintf('pushed %d object(s) to the %s remote', $pushed, $remote->name()));

        if ($graphKey !== null) {
            $output->writeln('pushed the ' . $branch . ' baseline (' . $graphKey . ')');
        }

        return Command::SUCCESS;
    }

    /** One object per test file the graph has results for; keys are recomputed from the graph's own edges. */
    private static function pushObjects(ObjectStore $objects, Graph $graph, string $root, string $branch): void
    {
        $contentKey = new ContentKey($root);
        $byFile = [];

        foreach ($graph->results($branch) as $testId => $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $byFile[$file][$testId] = $result;
            }
        }

        foreach ($byFile as $file => $results) {
            if ($graph->isNotCacheable($file)) {
                continue;
            }

            $key = $contentKey->forTestFile($graph, $file);

            if ($key !== null) {
                $objects->putObject($key, $file, $results);
            }
        }
    }
}
