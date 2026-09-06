<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

use Symfony\Component\Process\Process;

/**
 * Builds and runs a single `vendor/bin/phpunit` invocation, streaming its output through
 * unchanged. docs/INTERNALS.md "Wrapper pipeline" and step 12 (env passed to PHPUnit).
 *
 * Output passthrough: the child inherits the terminal (`Process::setTty()`) when STDOUT is
 * a TTY and the platform supports it; otherwise both pipes are streamed to STDOUT/STDERR as
 * they arrive. There is never a timeout: PHPUnit runs are allowed to take as long as they need.
 */
final class PhpunitProcess
{
    /**
     * @param list<string> $iniFlags e.g. ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=<root>']
     * @param list<string> $phpunitArgs everything after the config file / --no-coverage flag
     * @param array<string, string> $env extra environment variables, merged over the inherited one
     */
    public function run(
        string $phpunitBin,
        ?string $configFile,
        array $iniFlags,
        array $phpunitArgs,
        bool $appendNoCoverage,
        array $env,
        string $cwd,
    ): int {
        $command = [PHP_BINARY, ...$iniFlags, $phpunitBin];

        if ($configFile !== null) {
            $command[] = '-c';
            $command[] = $configFile;
        }

        if ($appendNoCoverage && ! self::hasOwnCoverageOption($phpunitArgs)) {
            $command[] = '--no-coverage';
        }

        array_push($command, ...$phpunitArgs);

        $process = new Process($command, $cwd, $env);
        $process->setTimeout(null);

        if (stream_isatty(STDOUT) && Process::isTtySupported()) {
            $process->setTty(true);
            $process->run();
        } else {
            $process->run(static function (string $type, string $data): void {
                fwrite($type === Process::ERR ? STDERR : STDOUT, $data);
            });
        }

        return $process->getExitCode() ?? 1;
    }

    /** @param list<string> $phpunitArgs */
    private static function hasOwnCoverageOption(array $phpunitArgs): bool
    {
        foreach ($phpunitArgs as $arg) {
            if ($arg === '--no-coverage' || str_starts_with($arg, '--coverage-')) {
                return true;
            }
        }

        return false;
    }
}
