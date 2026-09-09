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
 *
 * `$workerIsolation` ({@see WorkerIsolation}, constructed by the caller only once its own
 * gate says yes — for Laravel, {@see \Manuglopez\Replay\Laravel\ParallelIsolation::enabled()}
 * and {@see \Manuglopez\Replay\Laravel\ParallelIsolation::applicationResolvable()}, both
 * called from `RunPipeline` — never by this class) wires up whatever per-worker isolation
 * the collaborator's {@see WorkerIsolation::runnerClass()} names: a single `--runner=<class>`
 * argv token (never split across two array entries the way `--processes`/`--passthru-php`
 * are — the `=` form is deliberate, SPEC.md §13) plus `LARAVEL_PARALLEL_TESTING=1` in the
 * environment, both gated on that same non-null return so they can never disagree. A `null`
 * collaborator — no framework has one to contribute, or none was even constructed — makes
 * both a no-op: a plain `vendor/bin/paratest --processes=N` invocation, exactly today's
 * behaviour for a non-Laravel project or one that opted out.
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
        ?WorkerIsolation $workerIsolation = null,
    ): int {
        if (! is_file($paratestBin)) {
            Warnings::warn('--parallel requested but vendor/bin/paratest is missing; running PHPUnit sequentially');

            return (new PhpunitProcess())->run($phpunitBin, $configFile, $iniFlags, $phpunitArgs, $appendNoCoverage, $env, $cwd);
        }

        $command = $this->buildCommand($configFile, $iniFlags, $phpunitArgs, $appendNoCoverage, $paratestBin, $parallel, $workerIsolation);

        if ($workerIsolation?->runnerClass() !== null) {
            $env['LARAVEL_PARALLEL_TESTING'] = '1';
        }

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
     * @param list<string> $iniFlags
     * @param list<string> $phpunitArgs
     * @return list<string>
     */
    public function buildCommand(
        ?string $configFile,
        array $iniFlags,
        array $phpunitArgs,
        bool $appendNoCoverage,
        string $paratestBin,
        int $parallel,
        ?WorkerIsolation $workerIsolation = null,
    ): array {
        $command = [PHP_BINARY, ...$iniFlags, $paratestBin];

        if ($configFile !== null) {
            $command[] = '-c';
            $command[] = $configFile;
        }

        if ($parallel > 0) {
            $command[] = '--processes';
            $command[] = (string) $parallel;
        }

        $runnerClass = $workerIsolation?->runnerClass();

        if ($runnerClass !== null) {
            $command[] = '--runner=' . $runnerClass;
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

        return $command;
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
