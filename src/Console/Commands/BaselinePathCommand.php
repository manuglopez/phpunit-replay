<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay baseline-path` (SPEC.md §11): prints the resolved state directory and
 * nothing else, for CI to know what to archive as a build artifact.
 */
final class BaselinePathCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('baseline-path')
            ->setDescription('Prints the phpunit-replay state directory for this project.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cwd = getcwd() ?: '.';
        $git = new Git($cwd);
        $root = $git->topLevel() ?? (realpath($cwd) ?: $cwd);
        $config = Config::load($root);

        $output->writeln(StateDirectory::resolve($config->stateDir, $root));

        return Command::SUCCESS;
    }
}
