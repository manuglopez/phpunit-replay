<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

use Symfony\Component\Process\Process;

/**
 * Builds and runs a single `vendor/bin/paratest` invocation (SPEC.md §13, `--parallel`/`-p`),
 * mirroring {@see PhpunitProcess} in every way that matters to the caller: same env, same
 * TTY/streaming rules, same `--no-coverage` handling. The one structural difference is where
 * the coverage driver's ini flags go — Paratest's own process is never instrumented, only the
 * worker processes it spawns are, so the flags travel as a single `--passthru-php` argument
 * (see {@see self::passthruPhp()}) instead of sitting on the outer `php` command line.
 *
 * Paratest propagates a per-worker `TEST_TOKEN` environment variable itself
 * (`ParaTest\Options::fillEnvWithTokens()`, read by {@see \Manuglopez\Replay\Record\RunWriter}),
 * and its own worker application loads the `<extensions>` block from the `-c` configuration
 * file the same way a plain PHPUnit run does (`ParaTest\WrapperRunner\ApplicationForWrapperWorker`
 * iterates `$configuration->extensionBootstrappers()`), so nothing extra is needed for our
 * injected `ReplayExtension` bootstrap to load inside a worker.
 *
 * Falls back to a sequential {@see PhpunitProcess} run, with a warning, when `$paratestBin`
 * does not exist (the caller always passes `<root>/vendor/bin/paratest`, missing whenever
 * Paratest was not installed as a dev dependency).
 */
final class ParatestProcess
{
    /**
     * @param list<string> $iniFlags e.g. ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=<root>']
     * @param list<string> $phpunitArgs everything after the config file / --no-coverage flag
     * @param array<string, string> $env extra environment variables, merged over the inherited one
     * @param int $parallel 0 = Paratest's own "auto" process count (omit --processes); a
     *     positive int = that many processes
     */
    public function run(
        string $paratestBin,
        string $phpunitBin,
        ?string $configFile,
        array $iniFlags,
        array $phpunitArgs,
        bool $appendNoCoverage,
        array $env,
        string $cwd,
        int $parallel,
    ): int {
        if (! is_file($paratestBin)) {
            Warnings::warn('--parallel requested but vendor/bin/paratest is missing; running PHPUnit sequentially');

            return (new PhpunitProcess())->run($phpunitBin, $configFile, $iniFlags, $phpunitArgs, $appendNoCoverage, $env, $cwd);
        }

        $command = [PHP_BINARY, $paratestBin];

        if ($configFile !== null) {
            $command[] = '-c';
            $command[] = $configFile;
        }

        if ($parallel > 0) {
            $command[] = '--processes';
            $command[] = (string) $parallel;
        }

        $passthruPhp = self::passthruPhp($iniFlags);

        if ($passthruPhp !== null) {
            $command[] = '--passthru-php';
            $command[] = $passthruPhp;
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

    /**
     * Paratest re-parses its `--passthru-php` value as a shell command line
     * (`ParaTest\Options::parsePassthru()`: `php -r 'echo serialize($argv);' -- <value>`), so
     * each flag must arrive individually quoted — exactly the form its own `--help` documents:
     * `--passthru-php="'-d' 'pcov.enabled=1'"`.
     *
     * @param list<string> $iniFlags
     */
    private static function passthruPhp(array $iniFlags): ?string
    {
        if ($iniFlags === []) {
            return null;
        }

        return implode(' ', array_map(fn (string $flag): string => escapeshellarg($flag), $iniFlags));
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
