<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Console\Runner;

use Manuglopez\Replay\Console\Runner\ParatestProcess;
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

        $exitCode = (new ParatestProcess())->run(
            $this->fakeBin('paratest.php'),
            $this->fakeBin('never-called.php'),
            '/project/.phpunit-replay.xml',
            ['-d', 'pcov.enabled=1', '-d', 'pcov.directory=/project'],
            ['--filter', 'FooTest'],
            true,
            ['CAPTURE_FILE' => $capture],
            $this->dir,
            3,
        );

        self::assertSame(0, $exitCode);
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

    /** @return list<string> */
    private function captured(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
