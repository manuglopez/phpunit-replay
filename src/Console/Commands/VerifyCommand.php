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
 * `phpunit-replay verify` (SPEC.md §12.2): runs the full suite in record mode and compares
 * every result against the one the cache holds for it — reporting how much of the suite a
 * fast `run` lane would have served from cache instead (`would replay`), how many of those
 * cached results are wrong (`divergences`), and how many this pass could not check
 * (`unverified`). {@see \Manuglopez\Replay\Report\VerifySummary} defines each figure.
 */
final class VerifyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('verify')
            ->setDescription('Runs the full suite and reports how much of it the cache would have replayed, and how many of those cached results are wrong.')
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
