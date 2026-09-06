<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds a throw-away copy of tests/Fixtures/Projects/plain inside a fresh git
 * repository, with a `vendor/` shim so `vendor/bin/phpunit` runs against the
 * copy without ever running `composer install`.
 */
final class FixtureProject
{
    private ?string $homeDir = null;

    private function __construct(public readonly GitRepo $repo)
    {
    }

    /**
     * Copies tests/Fixtures/Projects/plain into a fresh GitRepo::init() root,
     * installs the vendor/ shim, and commits everything as "initial".
     */
    public static function plain(): self
    {
        $repo = GitRepo::init();

        TempDir::copyTree(self::projectsDir() . '/plain', $repo->root);
        self::installVendorShim($repo->root);
        $repo->commitAll('initial');

        return new self($repo);
    }

    public function root(): string
    {
        return $this->repo->root;
    }

    /** Copies an overlay file from plain-variants onto the working copy. */
    public function applyVariant(string $variantFile, string $targetRel): void
    {
        $source = self::projectsDir() . '/plain-variants/' . $variantFile;
        $content = file_get_contents($source);

        if ($content === false) {
            throw new RuntimeException('Cannot read variant ' . $source);
        }

        $this->write($targetRel, $content);
    }

    public function write(string $relative, string $content): void
    {
        $this->repo->write($relative, $content);
    }

    public function read(string $relative): string
    {
        return $this->repo->read($relative);
    }

    public function delete(string $relative): void
    {
        $this->repo->delete($relative);
    }

    /**
     * Runs `php -d pcov.enabled=1 -d pcov.directory=<root> vendor/bin/phpunit` inside the
     * fixture copy (pcov instruments nothing without an explicit `pcov.directory`).
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function phpunit(array $args = [], array $env = []): array
    {
        $process = new Process(
            ['php', '-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $this->root(), 'vendor/bin/phpunit', ...$args],
            $this->root(),
            $env,
        );
        $process->setTimeout(120.0);
        $process->run();

        return [
            'exitCode' => $process->getExitCode() ?? -1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * Runs the REAL `bin/phpunit-replay` wrapper as a subprocess, inside the fixture copy,
     * with `HOME` pointed at a temp directory stable across calls on this instance (so the
     * state dir it resolves to persists between a `record` call and a later `run` call) but
     * never the real developer `$HOME` (tests/Integration/*, docs/INTERNALS.md).
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function replay(array $args = [], array $env = []): array
    {
        $process = $this->replayProcess($args, $env);
        $process->run();

        return [
            'exitCode' => $process->getExitCode() ?? -1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * The same process `replay()` runs synchronously, returned unstarted so a test can
     * `start()` it, poll its state, and signal it directly (e.g. to exercise a SIGKILL
     * mid-run).
     *
     * @param list<string> $args
     * @param array<string, string> $env
     */
    public function replayProcess(array $args = [], array $env = []): Process
    {
        $process = new Process(
            ['php', '-d', 'pcov.enabled=1', '-d', 'pcov.directory=' . $this->root(), self::packageBin(), ...$args],
            $this->root(),
            ['HOME' => $this->homeDir(), ...$env],
        );
        $process->setTimeout(120.0);

        return $process;
    }

    /** The stable `$HOME` used by {@see self::replay()} for this fixture instance. */
    public function homeDir(): string
    {
        return $this->homeDir ??= TempDir::make('replay-home');
    }

    public function destroy(): void
    {
        $this->repo->destroy();

        if ($this->homeDir !== null) {
            TempDir::remove($this->homeDir);
        }
    }

    private static function packageBin(): string
    {
        return dirname(__DIR__, 2) . '/bin/phpunit-replay';
    }

    private static function projectsDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/Projects';
    }

    private static function installVendorShim(string $root): void
    {
        $packageRoot = dirname(__DIR__, 2);
        $packageAutoload = $packageRoot . '/vendor/autoload.php';
        $packagePhpunitBin = $packageRoot . '/vendor/phpunit/phpunit/phpunit';

        TempDir::write($root . '/vendor/autoload.php', self::autoloadShim($packageAutoload));
        TempDir::write($root . '/vendor/bin/phpunit', self::phpunitShim($packagePhpunitBin));

        if (! @chmod($root . '/vendor/bin/phpunit', 0o755)) {
            throw new RuntimeException('Cannot make ' . $root . '/vendor/bin/phpunit executable.');
        }
    }

    private static function autoloadShim(string $packageAutoload): string
    {
        $template = <<<'PHP'
        <?php

        declare(strict_types=1);

        require '__PACKAGE_AUTOLOAD__';

        spl_autoload_register(static function (string $class): void {
            $prefixes = [
                'App\\Tests\\' => __DIR__ . '/../tests/',
                'App\\' => __DIR__ . '/../src/',
            ];

            foreach ($prefixes as $prefix => $baseDir) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = substr($class, strlen($prefix));
                $path = $baseDir . str_replace('\\', '/', $relative) . '.php';

                if (is_file($path)) {
                    require $path;

                    return;
                }
            }
        });

        PHP;

        return str_replace('__PACKAGE_AUTOLOAD__', $packageAutoload, $template);
    }

    private static function phpunitShim(string $packagePhpunitBin): string
    {
        $template = <<<'PHP'
        #!/usr/bin/env php
        <?php

        declare(strict_types=1);

        $GLOBALS['_composer_autoload_path'] = __DIR__ . '/../autoload.php';

        require '__PACKAGE_PHPUNIT_BIN__';

        PHP;

        return str_replace('__PACKAGE_PHPUNIT_BIN__', $packagePhpunitBin, $template);
    }
}
