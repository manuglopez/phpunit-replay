<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Console\Runner\WorkerIsolation;
use Symfony\Component\Process\Process;

/**
 * Whether paratest workers should get Laravel's per-worker database isolation wired up:
 * `--runner=\Illuminate\Testing\ParallelRunner` on the paratest command line plus
 * `LARAVEL_PARALLEL_TESTING=1` in its environment (`Console\Runner\ParatestProcess`).
 *
 * Without both together, a Laravel project's `--parallel` run is not a clean failure: every
 * worker migrates/seeds the SAME database (`Illuminate\Testing\ParallelTesting::inParallel()`
 * is `! empty($_SERVER['LARAVEL_PARALLEL_TESTING']) && $this->token()`, so without the env var
 * no worker ever suffixes its database name), which typically surfaces as MySQL deadlocks or
 * duplicate-key errors rather than an obviously-parallel-unsafe-test failure. Reference
 * implementation this package is not otherwise a dependent of:
 * `Collision\Adapters\Laravel\Commands\TestCommand::paratestArguments()`/
 * `::paratestEnvironmentVariables()`.
 *
 * `Illuminate\Testing\ParallelRunner` itself only exists conditionally — its defining file
 * wraps the whole class declaration in `if (interface_exists(\ParaTest\RunnerInterface::class))`
 * — so passing `--runner` unconditionally on a non-Laravel project, or a Laravel project
 * without Paratest, would make Paratest fail outright trying to instantiate a class that was
 * never declared.
 *
 * Implements {@see \Manuglopez\Replay\Console\Runner\WorkerIsolation} so that
 * `Console\Runner\ParatestProcess` — a generic, framework-agnostic class — depends on that
 * abstraction instead of naming this Laravel-specific class, or its
 * `Illuminate\Testing\ParallelRunner` literal, directly: the same seam `Select\WatchDefault`,
 * `Record\CoverageDriver` and `Cache\OnceProcessClassifier` already give the core for other
 * framework- and driver-specific concerns. See {@see self::runnerClass()}.
 */
final class ParallelIsolation implements WorkerIsolation
{
    /**
     * The full gate for `RunPipeline::runPhpunit()`: the config opt-out, then three
     * technical conditions, all required — Laravel detected ({@see LaravelDetector}),
     * `vendor/bin/paratest` present, and the PROJECT's own `Illuminate\Testing\ParallelRunner`
     * class resolvable (checked in a throwaway subprocess, {@see self::projectHasParallelRunner()}
     * — never a plain `class_exists()` call in this process, for the same reason
     * {@see LaravelDetector} never uses one: this process may have loaded a different
     * Composer autoloader than the project's own, e.g. this package's own dev autoloader
     * when developing/testing it, so a naive check here would silently disagree with
     * what the project's real PHPUnit/Paratest process would see).
     *
     * The first three `false`s are silent — the user asked for it (opt-out), the feature
     * plainly does not apply (no Laravel), or the caller already warns about it downstream
     * ({@see \Manuglopez\Replay\Console\Runner\ParatestProcess::run()}'s own "vendor/bin/paratest
     * is missing" warning). The fourth is NOT silent: Laravel and Paratest both being present
     * but the project's `Illuminate\Testing\ParallelRunner` not resolving is exactly the
     * misconfiguration that reproduces the original bug (every worker migrating the same
     * database) if nothing says so — a warning here is the one thing standing between that
     * and a silent regression to the reported deadlocks.
     */
    public static function enabled(string $projectRoot, Config $config): bool
    {
        if (! $config->laravelParallelIsolation) {
            return false;
        }

        if (! LaravelDetector::enabled($projectRoot, $config)) {
            return false;
        }

        $root = rtrim($projectRoot, '/');

        if (! is_file($root . '/vendor/bin/paratest')) {
            return false;
        }

        if (self::projectHasParallelRunner($root)) {
            return true;
        }

        Warnings::warn(
            'Laravel and Paratest detected but Illuminate\Testing\ParallelRunner could not be resolved in the '
            . 'project; running --parallel without per-worker database isolation',
        );

        return false;
    }

    /**
     * The one condition {@see self::enabled()} cannot preemptively rule out from the
     * filesystem alone: `Illuminate\Testing\Concerns\RunsInParallel::createApplication()`
     * throws `RuntimeException('Parallel Runner unable to resolve application.')` when the
     * project has neither a `Tests\CreatesApplication` trait nor a `bootstrap/app.php` under
     * `Illuminate\Foundation\Application::inferBasePath()` (which resolves to the project
     * root here, since that's wherever the project's own Composer `ClassLoader` is
     * registered). Both are ordinary files at conventional, stable paths for every supported
     * Laravel version, so — consistent with {@see LaravelDetector} — this stays a plain file
     * check rather than requiring the project's autoloader just to ask `trait_exists()`.
     *
     * Detectable up front, deliberately: without this check, an otherwise-valid `--runner`
     * injection would make Paratest's own top-level process (not a worker) crash with an
     * uncaught `RuntimeException` before a single test runs — the "degrade, never explode"
     * failure this whole feature exists to avoid, not fix.
     */
    public static function applicationResolvable(string $projectRoot): bool
    {
        $root = rtrim($projectRoot, '/');

        return is_file($root . '/bootstrap/app.php') || is_file($root . '/tests/CreatesApplication.php');
    }

    /**
     * {@see \Manuglopez\Replay\Console\Runner\WorkerIsolation} contract: the FQCN Paratest's
     * `--runner` flag should load. Unconditional — whether THIS project should receive an
     * instance of this class at all is entirely {@see self::enabled()} and
     * {@see self::applicationResolvable()}'s job, decided by the caller
     * ({@see \Manuglopez\Replay\Console\Runner\RunPipeline::runPhpunit()}) before it ever
     * constructs one, the same division `Cache\OnceProcessClassifier`'s one implementation
     * uses. Narrows the interface's `?string` to a plain `string`, deliberately: this
     * implementation never has a `null` to return — Laravel's own per-worker isolation
     * runner is the only thing this class ever contributes — and PHP's covariant return
     * types allow an implementation to be more specific than the interface it satisfies.
     */
    public function runnerClass(): string
    {
        return '\Illuminate\Testing\ParallelRunner';
    }

    /**
     * Requires the PROJECT's own `vendor/autoload.php` in a throwaway `php -r` subprocess
     * (never this process) and checks `class_exists(\Illuminate\Testing\ParallelRunner::class)`
     * there. Cheap: it only registers Composer's PSR-4/classmap and — because `class_exists()`
     * triggers the autoloader — `require`s the one small `ParallelRunner.php` file; nothing
     * Laravel boots.
     */
    private static function projectHasParallelRunner(string $root): bool
    {
        $autoload = $root . '/vendor/autoload.php';

        if (! is_file($autoload)) {
            return false;
        }

        $probe = 'require $argv[1]; exit(class_exists(\Illuminate\Testing\ParallelRunner::class) ? 0 : 1);';

        $process = new Process([PHP_BINARY, '-r', $probe, '--', $autoload]);
        $process->run();

        return $process->isSuccessful();
    }
}
