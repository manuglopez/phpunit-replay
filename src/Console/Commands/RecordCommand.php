<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Commands;

use Manuglopez\Replay\Console\Runner\RunPipeline;
use Manuglopez\Replay\Console\Runner\RunRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `phpunit-replay record` (SPEC.md §3.3): runs the full suite with recording, unconditionally
 * (no selection). What CI runs on `main` to publish a fresh baseline.
 */
final class RecordCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('record')
            ->setDescription('Runs the full suite and records a fresh baseline.')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Ignore any cached baseline before recording.')
            ->addArgument('phpunit-args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Everything forwarded to vendor/bin/phpunit.')
        ;

        $this->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $phpunitArgs */
        $phpunitArgs = $input->getArgument('phpunit-args');

        $request = new RunRequest(
            cwd: getcwd() ?: '.',
            phpunitArgs: $phpunitArgs,
            fresh: $input->getOption('fresh') === true,
            noRemote: false,
            explain: false,
            dryRun: false,
            logJunit: null,
            allowCiBaseline: false,
            record: true,
        );

        return (new RunPipeline())->run($request);
    }
}
