<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\GraphStore;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\ExplainFormatter;
use Manuglopez\Replay\Console\Runner\ProjectLocator;
use Manuglopez\Replay\Laravel\LaravelIntegration;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Select\ResiduePatterns;
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

        $configuration = $configFile !== null ? $locator->buildConfiguration($configFile, [])[0] : null;
        $testPaths = $configuration !== null
            ? TestPaths::fromConfiguration($configuration, $root)
            : new TestPaths(['tests'], [], ['Test.php']);
        $scope = $configuration !== null ? SourceScope::fromProjectRoot($root, $configuration) : null;

        $watch = WatchPatterns::forProject($root, $testPaths->directories(), $config);

        /** @var string $path */
        $path = $input->getArgument('path');
        $rel = self::relativize($root, $cwd, $path);

        // SPEC.md §4.3.1: the same conservative watch fallback Select\RunListBuilder adds on
        // a real pass, through the same object — `explain` has to show the plan a real run
        // would produce, not a rosier one.
        $residue = new ResiduePatterns($graph, $testPaths, $config->staticDeclarationEdges, $scope);
        $watch->addFallback($residue->for([$rel]));
        $watch->addUnattributable($residue->unattributableFor([$rel]));

        $extraRules = LaravelIntegration::rulesFor($graph, $root, $config, $stateDir);
        // A rule that compares a file before and after (a schema dump) compares it with what
        // a pass would diff it from: the branch's baseline, else HEAD.
        $branch = $git->currentBranch();
        $base = ($branch !== null ? $graph->recordedSha($branch) : null) ?? $git->currentSha();
        $selection = Selector::default($graph, $testPaths, $watch, $root, $extraRules)->affected([$rel], $base);
        $runList = new RunList($selection, [], [], []);

        $lines = (new ExplainFormatter())->lines($runList);

        foreach ($lines as $line) {
            $output->writeln($line);
        }

        // Why a change selects nothing, when a rule knows (a squashed migration).
        foreach ((new ExplainFormatter())->noteLines($selection) as $line) {
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
