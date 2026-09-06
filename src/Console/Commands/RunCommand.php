<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Console\Runner\RunPipeline;
use Manuglopez\Replay\Console\Runner\RunRequest;
use Manuglopez\Replay\Console\Runner\Warnings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay run` (the default command, SPEC.md §11): runs only what changed,
 * replaying everything else from the cached graph.
 */
final class RunCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('run')
            ->setDescription('Runs only the tests affected by what changed; replays the rest from cache.')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Ignore any cached baseline and record a fresh one.')
            ->addOption('no-remote', null, InputOption::VALUE_NONE, 'Never contact a configured remote cache.')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Print which rule selected each test file, and why.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would run without running it; implies --explain.')
            ->addOption('log-junit', null, InputOption::VALUE_REQUIRED, 'Write a merged JUnit report (real + replayed results) to FILE.')
            ->addOption('allow-ci-baseline', null, InputOption::VALUE_NONE, 'Allow a CI run to publish a branch baseline (SPEC §12.1).')
            ->addOption('in-process', null, InputOption::VALUE_NONE, 'Accepted for forward compatibility: in-process mode arrives in phase 2.')
            ->addOption('filtered', null, InputOption::VALUE_NONE, 'Run in filtered mode (the default, and phase 1\'s only mode).')
            ->addArgument('phpunit-args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Everything forwarded to vendor/bin/phpunit.')
        ;

        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('in-process') === true) {
            Warnings::warn('in-process mode arrives in phase 2; running filtered');
        }

        $logJunit = $input->getOption('log-junit');

        /** @var list<string> $phpunitArgs */
        $phpunitArgs = $input->getArgument('phpunit-args');

        $request = new RunRequest(
            cwd: getcwd() ?: '.',
            phpunitArgs: $phpunitArgs,
            fresh: $input->getOption('fresh') === true,
            noRemote: $input->getOption('no-remote') === true,
            explain: $input->getOption('explain') === true,
            dryRun: $input->getOption('dry-run') === true,
            logJunit: is_string($logJunit) ? $logJunit : null,
            allowCiBaseline: $input->getOption('allow-ci-baseline') === true,
            record: false,
        );

        return (new RunPipeline())->run($request);
    }
}
