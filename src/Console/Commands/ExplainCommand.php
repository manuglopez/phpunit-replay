<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\ExplainFormatter;
use Manuglopez\Replay\Console\Runner\ProjectLocator;
use Manuglopez\Replay\Select\RunList;
use Manuglopez\Replay\Select\Selector;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Support\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay explain <path>` (SPEC.md §11): which recorded test files a change to
 * `<path>` would affect, and why — the same rule chain and formatting as `run --explain`.
 */
final class ExplainCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('explain')
            ->setDescription('Prints which recorded test files a change to <path> would affect, and why.')
            ->addArgument('path', InputArgument::REQUIRED, 'Project-relative (or absolute) path to a source or test file.')
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

        $store = new GraphStore($stateDir, $root);
        $graph = $store->load();

        if ($graph === null) {
            $output->writeln('no baseline yet');

            return Command::FAILURE;
        }

        $locator = new ProjectLocator();
        $configFile = $locator->resolveConfigFile($root, []);

        $testPaths = $configFile !== null
            ? TestPaths::fromConfiguration($locator->buildConfiguration($configFile, []), $root)
            : new TestPaths(['tests'], [], ['Test.php']);

        $watch = new WatchPatterns();
        $watch->useDefaults($root, $testPaths->directories());

        if ($config->watch !== []) {
            $watch->add($config->watch);
        }

        /** @var string $path */
        $path = $input->getArgument('path');
        $rel = self::relativize($root, $cwd, $path);

        $selection = Selector::default($graph, $testPaths, $watch, $root)->affected([$rel]);
        $runList = new RunList($selection, [], [], []);

        $lines = (new ExplainFormatter())->lines($runList);

        foreach ($lines as $line) {
            $output->writeln($line);
        }

        $output->writeln('');
        $output->writeln('direct dependents: ' . count($graph->testFilesDependingOn($rel)));

        if ($graph->fileId($rel) === null && $lines === []) {
            $output->writeln('no recorded test executes this file');
        }

        return Command::SUCCESS;
    }

    private static function relativize(string $root, string $cwd, string $path): string
    {
        $absoluteCandidate = Paths::isAbsolute($path) ? $path : rtrim($cwd, '/') . '/' . $path;
        $real = realpath($absoluteCandidate);

        return Paths::relative($root, $real !== false ? $real : $absoluteCandidate) ?? $path;
    }
}
