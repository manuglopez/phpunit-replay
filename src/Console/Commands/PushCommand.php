<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Cache\Remote\Exchange;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\Runner\ProjectLocator;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Select\NonEdgeInputs;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Support\Paths;
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
 * The graph goes only from a tree clean of anything a result could depend on
 * (`Cache\GraphPublication`: a README edit or a rewritten `phpunit.xml` does not count), and
 * carries only the branch's results stamped for this tree (`Cache\Remote\Exchange`). With a
 * relevant file modified or untracked, the objects are still pushed, the graph is not, one
 * line names the files, and the command exits 1: `--graph` was asked for and not done. Digests
 * are recomputed from the PHPUnit configuration the graph was recorded with
 * (`Graph::configuration()`), so a baseline recorded with `-c` publishes its objects too.
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

        $hashes = FileHashes::inStateDir($root, $stateDir);
        [$testPaths, $scope] = self::configuration($root, $graph);
        $inputs = NonEdgeInputs::forProject($graph, $root, $config, $testPaths, $hashes, $git, $stateDir, $scope);

        $remote->begin();

        $objects = new ObjectStore($remote, $stateDir, ProjectKey::shared($root));
        $exchange = new Exchange($objects, $graph, new ContentKey($root, $hashes), $inputs);
        $graphKey = null;
        $graphPutError = null;
        $refusal = null;

        try {
            // Only results stamped with the current key and digest, published under both.
            $exchange->publishObjects(self::testFilesWithResults($graph, $branch), $graph->results($branch));

            if ($input->getOption('graph') === true) {
                // Only from a tree clean of anything a result could depend on
                // (Cache\GraphPublication), and only the results stamped for this tree.
                ['refusal' => $refusal, 'published' => $published] = $exchange->publishGraph($branch, new ChangedFiles($root, $git), 'the ' . $branch . ' baseline');

                if ($refusal === null && ! $published) {
                    // Captured now, before end() can overwrite lastError() with a (possibly
                    // unrelated) push failure of its own.
                    $graphPutError = $remote->lastError() ?? 'unknown error';
                } elseif ($refusal === null) {
                    $graphKey = ObjectStore::graphKey(ProjectKey::shared($root), $branch);
                }
            }

            $hashes->save();
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

        // Asked for and not done: the objects went, the graph did not, and the exit code says so.
        if ($refusal !== null) {
            $output->writeln($refusal);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * The `<testsuites>` and `<source>` of the PHPUnit configuration the graph was recorded
     * with (`Graph::configuration()`, a `run -c <file>` included), else the one a plain `run`
     * resolves: the digests recomputed here must be the ones the results were stamped with.
     *
     * @return array{0: TestPaths, 1: ?SourceScope}
     */
    private static function configuration(string $root, Graph $graph): array
    {
        $locator = new ProjectLocator();
        $recorded = $graph->configuration();
        $configFile = $recorded !== null && is_file(Paths::join($root, $recorded))
            ? Paths::join($root, $recorded)
            : $locator->resolveConfigFile($root, []);

        if ($configFile === null) {
            return [new TestPaths(['tests'], [], ['Test.php']), null];
        }

        $configuration = $locator->buildConfiguration($configFile, [])[0];

        return [TestPaths::fromConfiguration($configuration, $root), SourceScope::fromProjectRoot($root, $configuration)];
    }

    /** @return list<string> */
    private static function testFilesWithResults(Graph $graph, string $branch): array
    {
        $files = [];

        foreach ($graph->results($branch) as $result) {
            $file = $result['file'] ?? null;

            if (is_string($file) && $file !== '') {
                $files[$file] = true;
            }
        }

        return array_map(strval(...), array_keys($files));
    }
}
