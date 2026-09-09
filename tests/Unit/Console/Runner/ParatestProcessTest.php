<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Console\Runner;

use Manuglopez\Replay\Console\Runner\ParatestProcess;
use Manuglopez\Replay\Console\Runner\WorkerIsolation;
use Manuglopez\Replay\Laravel\ParallelIsolation;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real command line {@see ParatestProcess} builds (SPEC.md §13): `$paratestBin`
 * and `$phpunitBin` point at tiny fake PHP scripts (see {@see self::fakeBin()}) that record
 * their own argv instead of actually running Paratest or PHPUnit, so this stays a fast unit
 * test while still spawning a real process end to end.
 */
final class ParatestProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = TempDir::make('paratest-process');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function builds_the_command_line_with_processes_passthru_php_and_no_coverage(): void
    {
        $capture = $this->dir . '/captured.json';
        $iniFlags = ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=/project'];
        $paratestBin = $this->fakeBin('paratest.php');

        $exitCode = (new ParatestProcess())->run(
            $paratestBin,
            $this->fakeBin('never-called.php'),
            '/project/.phpunit-replay.xml',
            $iniFlags,
            ['--filter', 'FooTest'],
            true,
            ['CAPTURE_FILE' => $capture],
            $this->dir,
            3,
        );

        self::assertSame(0, $exitCode);

        // The script's $argv does not include PHP CLI flags (they're consumed by PHP before
        // the script runs), so we verify them separately through the command-building logic.
        // We use reflection to test that the main command contains the ini flags before
        // the paratest binary, while --passthru-php still carries them for the workers.
        $process = new ParatestProcess();
        $command = $process->buildCommand(
            '/project/.phpunit-replay.xml',
            $iniFlags,
            ['--filter', 'FooTest'],
            true,
            $paratestBin,
            3,
        );

        // Verify ini flags are in the main command before the paratest binary
        self::assertSame(PHP_BINARY, $command[0]);
        self::assertSame('-d', $command[1]);
        self::assertSame('pcov.enabled=1', $command[2]);
        self::assertSame('-d', $command[3]);
        self::assertSame('pcov.directory=/project', $command[4]);
        self::assertSame($paratestBin, $command[5]);

        // Verify --passthru-php still carries the ini flags
        self::assertContains('--passthru-php', $command);
        $passthruIndex = array_search('--passthru-php', $command);
        self::assertNotFalse($passthruIndex);
        self::assertSame("'-d' 'pcov.enabled=1' '-d' 'pcov.directory=/project'", $command[$passthruIndex + 1]);

        // Verify the script's $argv is as expected (no PHP CLI flags, they're consumed by PHP)
        self::assertSame([
            '-c', '/project/.phpunit-replay.xml',
            '--processes', '3',
            '--passthru-php', "'-d' 'pcov.enabled=1' '-d' 'pcov.directory=/project'",
            '--no-coverage',
            '--filter', 'FooTest',
        ], $this->captured($capture));
    }

    #[Test]
    public function omits_processes_when_parallel_is_zero_paratests_own_auto(): void
    {
        $capture = $this->dir . '/captured.json';

        (new ParatestProcess())->run(
            $this->fakeBin('paratest.php'),
            $this->fakeBin('never-called.php'),
            null,
            [],
            [],
            false,
            ['CAPTURE_FILE' => $capture],
            $this->dir,
            0,
        );

        self::assertSame([], $this->captured($capture));
    }

    #[Test]
    public function omits_passthru_php_when_there_are_no_ini_flags(): void
    {
        $capture = $this->dir . '/captured.json';

        (new ParatestProcess())->run(
            $this->fakeBin('paratest.php'),
            $this->fakeBin('never-called.php'),
            null,
            [],
            [],
            false,
            ['CAPTURE_FILE' => $capture],
            $this->dir,
            2,
        );

        self::assertSame(['--processes', '2'], $this->captured($capture));
    }

    #[Test]
    public function skips_the_no_coverage_flag_when_the_caller_already_passed_a_coverage_option(): void
    {
        $capture = $this->dir . '/captured.json';

        (new ParatestProcess())->run(
            $this->fakeBin('paratest.php'),
            $this->fakeBin('never-called.php'),
            null,
            [],
            ['--coverage-text'],
            true,
            ['CAPTURE_FILE' => $capture],
            $this->dir,
            1,
        );

        self::assertSame(['--processes', '1', '--coverage-text'], $this->captured($capture));
    }

    #[Test]
    public function adds_the_runner_flag_as_a_single_token_when_laravel_parallel_isolation_is_requested(): void
    {
        // ParatestProcess no longer knows the FQCN itself (see WorkerIsolation); the real
        // Laravel adapter is what supplies it now, so this test injects it instead of a bool.
        $command = (new ParatestProcess())->buildCommand(
            null,
            [],
            [],
            false,
            $this->fakeBin('paratest.php'),
            2,
            new ParallelIsolation(),
        );

        self::assertContains('--runner=\Illuminate\Testing\ParallelRunner', $command);
    }

    #[Test]
    public function omits_the_runner_flag_when_laravel_parallel_isolation_is_not_requested(): void
    {
        $command = (new ParatestProcess())->buildCommand(
            null,
            [],
            [],
            false,
            $this->fakeBin('paratest.php'),
            2,
        );

        foreach ($command as $arg) {
            self::assertStringNotContainsString('ParallelRunner', $arg);
        }
    }

    /**
     * Proves the seam is real, not a rename: a hand-rolled {@see WorkerIsolation} that never
     * references Laravel or Illuminate still gets its FQCN onto the command line verbatim
     * (ParatestProcess cannot be secretly reading it from somewhere Laravel-specific), and a
     * collaborator whose own {@see WorkerIsolation::runnerClass()} returns `null` produces the
     * exact same command line as passing no collaborator at all — i.e. the same output as
     * {@see self::omits_the_runner_flag_when_laravel_parallel_isolation_is_not_requested()},
     * today's Laravel-absent shape.
     */
    #[Test]
    public function honours_a_hand_written_worker_isolation_and_a_null_runner_class_matches_laravel_absent(): void
    {
        $paratestBin = $this->fakeBin('paratest.php');

        $customIsolation = new class () implements WorkerIsolation {
            public function runnerClass(): ?string
            {
                return '\Fixture\CustomWorkerRunner';
            }
        };

        $commandWithCustomRunner = (new ParatestProcess())->buildCommand(null, [], [], false, $paratestBin, 2, $customIsolation);

        self::assertContains('--runner=\Fixture\CustomWorkerRunner', $commandWithCustomRunner);

        $contributingNothing = new class () implements WorkerIsolation {
            public function runnerClass(): ?string
            {
                return null;
            }
        };

        $commandWithNullCollaborator = (new ParatestProcess())->buildCommand(null, [], [], false, $paratestBin, 2, $contributingNothing);
        $commandWithNoCollaboratorAtAll = (new ParatestProcess())->buildCommand(null, [], [], false, $paratestBin, 2, null);

        self::assertSame($commandWithNoCollaboratorAtAll, $commandWithNullCollaborator);
    }

    #[Test]
    public function sets_laravel_parallel_testing_in_the_environment_when_requested(): void
    {
        $capture = $this->dir . '/captured-env.json';

        // Same reasoning as adds_the_runner_flag_as_a_single_token_...() above: the bool is
        // gone, so requesting isolation means injecting the collaborator that contributes it.
        (new ParatestProcess())->run(
            $this->fakeEnvCapturingBin('paratest-env-on.php', 'LARAVEL_PARALLEL_TESTING'),
            $this->fakeBin('never-called.php'),
            null,
            [],
            [],
            false,
            ['CAPTURE_FILE' => $capture],
            $this->dir,
            1,
            new ParallelIsolation(),
        );

        self::assertSame("'1'", (string) file_get_contents($capture));
    }

    #[Test]
    public function does_not_set_laravel_parallel_testing_when_not_requested(): void
    {
        $capture = $this->dir . '/captured-env.json';

        (new ParatestProcess())->run(
            $this->fakeEnvCapturingBin('paratest-env-off.php', 'LARAVEL_PARALLEL_TESTING'),
            $this->fakeBin('never-called.php'),
            null,
            [],
            [],
            false,
            ['CAPTURE_FILE' => $capture],
            $this->dir,
            1,
        );

        self::assertSame('false', (string) file_get_contents($capture));
    }

    #[Test]
    public function falls_back_to_phpunit_process_and_forwards_its_exit_code_when_the_paratest_binary_is_missing(): void
    {
        $capture = $this->dir . '/captured.json';

        $exitCode = (new ParatestProcess())->run(
            $this->dir . '/does-not-exist/vendor/bin/paratest',
            $this->fakeBin('phpunit.php'),
            '/project/phpunit.xml',
            ['-d', 'pcov.enabled=1'],
            ['--filter', 'FooTest'],
            true,
            ['CAPTURE_FILE' => $capture, 'EXIT_CODE' => '5'],
            $this->dir,
            2,
        );

        self::assertSame(5, $exitCode);

        // PhpunitProcess's own command shape: the `-d` flags are consumed by the PHP CLI
        // itself (never reach the script's $argv), then `-c`, `--no-coverage`, the rest.
        self::assertSame(
            ['-c', '/project/phpunit.xml', '--no-coverage', '--filter', 'FooTest'],
            $this->captured($capture),
        );
    }

    /**
     * Path to a tiny PHP script that dumps `array_slice($argv, 1)` as JSON to
     * `getenv('CAPTURE_FILE')` and exits with `(int) getenv('EXIT_CODE')`, or 0.
     */
    private function fakeBin(string $name): string
    {
        $path = $this->dir . '/bin/' . $name;

        TempDir::write($path, <<<'PHP'
        <?php

        declare(strict_types=1);

        $capture = getenv('CAPTURE_FILE');
        file_put_contents((string) $capture, (string) json_encode(array_slice($argv, 1)));

        $exitCode = getenv('EXIT_CODE');
        exit($exitCode === false ? 0 : (int) $exitCode);

        PHP);

        return $path;
    }

    /**
     * Path to a tiny PHP script that dumps `var_export(getenv($envKey), true)` to
     * `getenv('CAPTURE_FILE')` — `"'1'"` when the env var is `'1'`, the literal string
     * `'false'` when it is not set at all (`getenv()`'s own "unset" return value).
     */
    private function fakeEnvCapturingBin(string $name, string $envKey): string
    {
        $path = $this->dir . '/bin/' . $name;

        TempDir::write($path, str_replace('__ENV_KEY__', $envKey, <<<'PHP'
        <?php

        declare(strict_types=1);

        $capture = getenv('CAPTURE_FILE');
        file_put_contents((string) $capture, var_export(getenv('__ENV_KEY__'), true));

        exit(0);

        PHP));

        return $path;
    }

    /** @return list<string> */
    private function captured(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
