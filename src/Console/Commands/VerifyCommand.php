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
 * `phpunit-replay verify` (SPEC.md §12.2): runs the full suite in record mode and
 * compares every result against what a normal replay pass would have served from cache.
 */
final class VerifyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('verify')
            ->setDescription('Runs the full suite and reports how many results diverge from what the cache would have replayed.')
            ->addOption('parallel', 'p', InputOption::VALUE_OPTIONAL, 'Run through Paratest. N processes, or Paratest\'s own auto-detected count when omitted (SPEC §13).', false)
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
            fresh: false,
            noRemote: false,
            explain: false,
            dryRun: false,
            logJunit: null,
            allowCiBaseline: false,
            record: false,
            parallel: RunRequest::parseParallel($input->getOption('parallel')),
        );

        return (new RunPipeline())->runVerify($request);
    }
}
