<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\ParallelIsolation;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `ParallelIsolation::enabled()` (the paratest `--runner`/`LARAVEL_PARALLEL_TESTING` gate,
 * SPEC.md §13) requires all of: the config opt-out not set, {@see \Manuglopez\Replay\Laravel\LaravelDetector}
 * saying yes, `vendor/bin/paratest` present, and the PROJECT's own
 * `Illuminate\Testing\ParallelRunner` resolvable — checked via a real subprocess that
 * `require`s a fake `vendor/autoload.php`, so these tests exercise the actual probe
 * mechanism without needing a real Composer-installed Laravel/Paratest (see
 * tests/Integration/LaravelLiteFixtureTest.php for that end-to-end coverage).
 */
final class ParallelIsolationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('parallel-isolation');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    public function test_disabled_by_config_opt_out_even_when_every_other_condition_passes(): void
    {
        $this->makeFullyQualifiedProject();

        $config = Config::fromArray(['laravel_parallel_isolation' => false]);

        self::assertFalse(ParallelIsolation::enabled($this->root, $config));
    }

    public function test_disabled_without_an_artisan_file(): void
    {
        // Everything else present, but LaravelDetector::enabled() requires `artisan`.
        TempDir::write($this->root . '/vendor/bin/paratest', '#!/usr/bin/env php');
        $this->writeAutoloadDefiningParallelRunner();

        self::assertFalse(ParallelIsolation::enabled($this->root, Config::defaults()));
    }

    public function test_disabled_when_paratest_is_missing(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');
        $this->writeAutoloadDefiningParallelRunner();

        self::assertFalse(ParallelIsolation::enabled($this->root, Config::defaults()));
    }

    public function test_disabled_when_the_projects_own_parallel_runner_class_is_not_resolvable(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');
        TempDir::write($this->root . '/vendor/bin/paratest', '#!/usr/bin/env php');

        // A vendor/autoload.php that exists but never defines Illuminate\Testing\ParallelRunner
        // (e.g. Paratest not actually reachable from the project's own autoloader, or an
        // older illuminate/testing without the class at all).
        TempDir::write($this->root . '/vendor/autoload.php', "<?php\n// no relevant classes here\n");

        self::assertFalse(ParallelIsolation::enabled($this->root, Config::defaults()));
    }

    public function test_disabled_when_the_project_has_no_vendor_autoload_at_all(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');
        TempDir::write($this->root . '/vendor/bin/paratest', '#!/usr/bin/env php');

        self::assertFalse(ParallelIsolation::enabled($this->root, Config::defaults()));
    }

    public function test_enabled_when_every_condition_passes(): void
    {
        $this->makeFullyQualifiedProject();

        self::assertTrue(ParallelIsolation::enabled($this->root, Config::defaults()));
    }

    /**
     * The one `enabled() === false` path that is NOT silent (see the method's own docblock):
     * Laravel and Paratest are both present, but the project's own `Illuminate\Testing\ParallelRunner`
     * did not resolve — exactly the misconfiguration that would otherwise silently reproduce
     * the original bug (every worker migrating the same database). Run in a subprocess: the
     * warning writes straight to the real `STDERR` stream ({@see \Manuglopez\Replay\Console\Runner\Warnings::warn()}),
     * which cannot be safely intercepted from inside this same process without risking
     * cross-test contamination.
     */
    public function test_warns_when_paratest_and_laravel_are_present_but_the_parallel_runner_class_is_not_resolvable(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');
        TempDir::write($this->root . '/vendor/bin/paratest', '#!/usr/bin/env php');
        TempDir::write($this->root . '/vendor/autoload.php', "<?php\n// no relevant classes here\n");

        $result = $this->callEnabledInSubprocess();

        self::assertSame('0', $result['stdout']);
        self::assertStringContainsString(
            'phpunit-replay: Laravel and Paratest detected but Illuminate\Testing\ParallelRunner could not be '
            . 'resolved in the project; running --parallel without per-worker database isolation',
            $result['stderr'],
        );
    }

    public function test_does_not_warn_when_disabled_by_config_opt_out(): void
    {
        $this->makeFullyQualifiedProject();

        $result = $this->callEnabledInSubprocess(optOut: true);

        self::assertSame('0', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function test_does_not_warn_without_an_artisan_file(): void
    {
        TempDir::write($this->root . '/vendor/bin/paratest', '#!/usr/bin/env php');
        $this->writeAutoloadDefiningParallelRunner();

        $result = $this->callEnabledInSubprocess();

        self::assertSame('0', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function test_does_not_warn_when_paratest_is_missing(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');
        $this->writeAutoloadDefiningParallelRunner();

        $result = $this->callEnabledInSubprocess();

        self::assertSame('0', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function test_does_not_warn_when_every_condition_passes(): void
    {
        $this->makeFullyQualifiedProject();

        $result = $this->callEnabledInSubprocess();

        self::assertSame('1', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function test_application_resolvable_with_a_bootstrap_app_php(): void
    {
        TempDir::write($this->root . '/bootstrap/app.php', "<?php\nreturn null;\n");

        self::assertTrue(ParallelIsolation::applicationResolvable($this->root));
    }

    public function test_application_resolvable_with_a_creates_application_trait_file(): void
    {
        TempDir::write($this->root . '/tests/CreatesApplication.php', "<?php\nnamespace Tests;\ntrait CreatesApplication {}\n");

        self::assertTrue(ParallelIsolation::applicationResolvable($this->root));
    }

    public function test_application_not_resolvable_without_either_file(): void
    {
        self::assertFalse(ParallelIsolation::applicationResolvable($this->root));
    }

    private function makeFullyQualifiedProject(): void
    {
        TempDir::write($this->root . '/artisan', '#!/usr/bin/env php');
        TempDir::write($this->root . '/vendor/bin/paratest', '#!/usr/bin/env php');
        $this->writeAutoloadDefiningParallelRunner();
    }

    /**
     * Calls `ParallelIsolation::enabled($this->root, ...)` in a throwaway subprocess (this
     * package's own `vendor/autoload.php`, not the fake project one under test) and returns
     * its stdout ("1"/"0" for the boolean result) and real stderr — the only reliable way to
     * observe {@see \Manuglopez\Replay\Console\Runner\Warnings::warn()} output without
     * touching this test process's own STDERR.
     *
     * @return array{stdout: string, stderr: string}
     */
    private function callEnabledInSubprocess(bool $optOut = false): array
    {
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

        $code = <<<'PHP'
        require $argv[1];
        $config = \Manuglopez\Replay\Config::defaults()->with(['laravelParallelIsolation' => $argv[3] !== '1']);
        echo \Manuglopez\Replay\Laravel\ParallelIsolation::enabled($argv[2], $config) ? '1' : '0';
        PHP;

        $process = new Process([PHP_BINARY, '-r', $code, '--', $autoload, $this->root, $optOut ? '1' : '0']);
        $process->run();

        return ['stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    private function writeAutoloadDefiningParallelRunner(): void
    {
        TempDir::write($this->root . '/vendor/autoload.php', <<<'PHP'
        <?php

        namespace Illuminate\Testing;

        class ParallelRunner
        {
        }

        PHP);
    }
}
