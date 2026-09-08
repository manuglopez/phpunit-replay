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
    public static function plain(?string $root = null): self
    {
        return self::fromProject('plain', $root);
    }

    /**
     * A second checkout of THIS project, git history and all, under `$root` — a second
     * machine for tests that need two of them sharing a remote cache (SPEC.md §9). The
     * copy keeps the same commits (so a baseline sha recorded on one is an ancestor of the
     * other's HEAD) and, as long as the `origin` remote is unchanged, resolves to the same
     * `Cache\ProjectKey::shared()` regardless of `$root`'s basename — which is what makes
     * `graph/<key>/<branch>.json` shared rather than per-checkout, exactly like two
     * developers cloning the same repository into differently named directories.
     * `Cache\ProjectKey::for()` (the LOCAL state directory key) stays basename-sensitive by
     * design, so it differs when `$root`'s basename differs from the original. Its `$HOME`
     * — and therefore its state directory — is its own.
     */
    public function copyTo(string $root): self
    {
        TempDir::copyTree($this->root(), $root);

        // copy() does not preserve the executable bit; rewriting the shims (byte-identical,
        // and gitignored anyway) restores it without dirtying the working tree.
        self::installVendorShim($root);

        return new self(GitRepo::at($root));
    }

    /**
     * Same, for tests/Fixtures/Projects/declarations: four test files over two
     * declaration-only source files (an enum and a constants class), one file with real
     * method bodies, and a `lang/` style `return [...]` file nothing loads — the fixture
     * for SPEC.md §9 `static_declaration_edges`.
     */
    public static function declarations(?string $root = null): self
    {
        return self::fromProject('declarations', $root);
    }

    /**
     * Same, for tests/Fixtures/Projects/inprocess: the plain fixture whose test classes
     * extend a base `App\Tests\TestCase` carrying the `Replayable` trait, with the
     * extension registered in its own phpunit.xml (SPEC.md §3.2).
     */
    public static function inprocess(?string $root = null): self
    {
        return self::fromProject('inprocess', $root);
    }

    private static function fromProject(string $name, ?string $root = null): self
    {
        $repo = GitRepo::init($root);

        TempDir::copyTree(self::projectsDir() . '/' . $name, $repo->root);
        self::installVendorShim($repo->root);
        $repo->commitAll('initial');

        return new self($repo);
    }

    /**
     * Copies tests/Fixtures/Projects/laravel-lite — including its installed vendor/ — into a
     * fresh GitRepo::init() root; composer install is never re-run per test.
     *
     * Deviation from a plain "symlink `<root>/vendor` to the fixture's installed vendor/":
     * PHP resolves `__DIR__`/`__FILE__` for an included file to its *fully resolved* path,
     * symlinks and all (verified empirically) — so with `vendor` itself symlinked, every
     * composer-autoloaded class (the whole `App\` namespace included) would load from the
     * *original* fixture path rather than this copy, and pcov (scoped to this copy's root)
     * would never see it. `vendor/` is instead copied for real, except its one internal
     * symlink (the `manuglopez/phpunit-replay` composer path-repo entry, `symlink: true` in
     * the fixture's composer.json) — copied AS a symlink, pointing at the same fully-resolved
     * absolute target, by {@see TempDir::copyTree()}: copying it byte-for-byte would recurse
     * forever, since that target is the package root, which contains this very fixture.
     * Use {@see self::laravelLiteAvailable()} to skip when that vendor/ was never installed.
     */
    public static function laravelLite(): self
    {
        $fixtureDir = self::projectsDir() . '/laravel-lite';

        if (! is_file($fixtureDir . '/vendor/autoload.php')) {
            throw new RuntimeException(
                'tests/Fixtures/Projects/laravel-lite/vendor is missing — run composer install there first '
                . '(see tests/Fixtures/Projects/laravel-lite/README.md).',
            );
        }

        $repo = GitRepo::init();

        TempDir::copyTree($fixtureDir, $repo->root);

        $repo->commitAll('initial');

        return new self($repo);
    }

    /** True when tests/Fixtures/Projects/laravel-lite/vendor/autoload.php exists (composer install was run there). */
    public static function laravelLiteAvailable(): bool
    {
        return is_file(self::projectsDir() . '/laravel-lite/vendor/autoload.php');
    }

    public function root(): string
    {
        return $this->repo->root;
    }

    /** True when this package's own vendor/bin/paratest is installed (SPEC.md §13, dev dependency). */
    public static function paratestAvailable(): bool
    {
        return is_file(dirname(__DIR__, 2) . '/vendor/brianium/paratest/bin/paratest');
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
     * `$env` is layered on top of {@see self::sanitizedEnv()}, so a value passed here
     * always wins over the sanitised default (e.g. a test that wants to exercise CI mode
     * on purpose can still pass `['CI' => 'true']`).
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
            self::sanitizedEnv($env),
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
     * Same as {@see self::phpunit()} but runs `vendor/bin/paratest` directly, bypassing
     * phpunit-replay entirely — for a control/"before the fix" run exactly the way a
     * developer's own `vendor/bin/paratest -p N` invocation would (tests/Integration/LaravelLiteParallelDatabaseTest.php).
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function paratest(array $args = [], array $env = []): array
    {
        $process = new Process(
            ['php', 'vendor/bin/paratest', ...$args],
            $this->root(),
            self::sanitizedEnv($env),
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
     * Runs PHPUnit inside the fixture copy the way a developer would (no wrapper), with
     * `HOME` pointed at this instance's temp home so the extension's state directory is
     * isolated. `CI`/`GITHUB_*`/`PHPUNIT_REPLAY_*` are already stripped by
     * {@see self::phpunit()} so a CI run of the package's own suite does not stop the
     * fixture from publishing its baseline.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function phpunitInProcess(array $args = [], array $env = []): array
    {
        return $this->phpunit($args, ['HOME' => $this->homeDir(), ...$env]);
    }

    /**
     * Runs the REAL `bin/phpunit-replay` wrapper as a subprocess, inside the fixture copy,
     * with `HOME` pointed at a temp directory stable across calls on this instance (so the
     * state dir it resolves to persists between a `record` call and a later `run` call) but
     * never the real developer `$HOME` (tests/Integration/*, docs/INTERNALS.md).
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @param list<string>|null $wrapperIniFlags `-d` flags for the WRAPPER process itself
     *        (as opposed to the flags the wrapper adds to the CHILD PhpunitProcess it
     *        launches, which are unaffected by this parameter). Defaults to enabling pcov
     *        for the wrapper too (`['pcov.enabled=1', 'pcov.directory=' . $this->root()]`),
     *        matching how these tests have always run it; pass `[]` to reproduce a plain
     *        `php vendor/bin/phpunit-replay` invocation, where the wrapper process has no
     *        coverage driver available to itself even though the child PHPUnit process does.
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    public function replay(array $args = [], array $env = [], ?array $wrapperIniFlags = null): array
    {
        $process = $this->replayProcess($args, $env, $wrapperIniFlags);
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
     * @param list<string>|null $wrapperIniFlags see {@see self::replay()}
     */
    public function replayProcess(array $args = [], array $env = [], ?array $wrapperIniFlags = null): Process
    {
        $wrapperIniFlags ??= ['pcov.enabled=1', 'pcov.directory=' . $this->root()];

        $iniArgs = [];

        foreach ($wrapperIniFlags as $flag) {
            $iniArgs[] = '-d';
            $iniArgs[] = $flag;
        }

        $process = new Process(
            ['php', ...$iniArgs, self::packageBin(), ...$args],
            $this->root(),
            self::sanitizedEnv(['HOME' => $this->homeDir(), ...$env]),
        );
        $process->setTimeout(120.0);

        return $process;
    }

    /**
     * A subprocess spawned via {@see Process} inherits every variable of this test
     * runner's own environment for any key not explicitly set here (Symfony always falls
     * back to `getenv()` for missing keys — passing an array with a key simply *absent*
     * does not hide it from the child). GitHub Actions exports `CI=true`,
     * `GITHUB_ACTIONS=true` and a raft of `GITHUB_*` variables, and a developer's shell
     * may carry a stray `PHPUNIT_REPLAY_*` from a previous manual run — any of those
     * leaking into a fixture subprocess changes its behaviour (SPEC.md §12.1: a CI-mode
     * wrapper never publishes a baseline), which is exactly the failure this method
     * exists to prevent.
     *
     * Blanks (`''`, not omits) every `CI`, `GITHUB_*` and `PHPUNIT_REPLAY_*` variable
     * present in the current process's environment, then layers `$env` on top so a test
     * that wants one of them back (e.g. to exercise CI mode on purpose) can still pass it
     * explicitly — an explicit value always wins over the blanked default.
     *
     * @param array<string, string> $env
     * @return array<string, string>
     */
    private static function sanitizedEnv(array $env): array
    {
        $blanked = [];

        foreach (array_keys(getenv()) as $name) {
            if ($name === 'CI' || str_starts_with($name, 'GITHUB_') || str_starts_with($name, 'PHPUNIT_REPLAY_')) {
                $blanked[$name] = '';
            }
        }

        return [...$blanked, ...$env];
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
        $packageParatestBin = $packageRoot . '/vendor/brianium/paratest/bin/paratest';

        TempDir::write($root . '/vendor/autoload.php', self::autoloadShim($packageAutoload));
        TempDir::write($root . '/vendor/bin/phpunit', self::phpunitShim($packagePhpunitBin));

        if (! @chmod($root . '/vendor/bin/phpunit', 0o755)) {
            throw new RuntimeException('Cannot make ' . $root . '/vendor/bin/phpunit executable.');
        }

        // Paratest support (SPEC.md §13): only when the package itself has it installed
        // (a dev dependency — composer install --no-dev environments won't have it).
        // ParaTest\Options::getPhpunitBinary() resolves PHPUnit relative to wherever its own
        // files physically live on disk ("a static non-customizable reference", its own words)
        // rather than the current project's vendor/bin/phpunit, so this shim cannot redirect
        // it to the fixture copy's own PHPUnit the way vendor/bin/phpunit's `_composer_autoload_path`
        // trick does; empirically (see the parallel-support commit) that reference is never
        // actually exercised by ParaTest's WrapperRunner (it runs PHPUnit in-process through
        // its own `bin/phpunit-wrapper.php`), and the fixture's `App\`/`App\Tests\` classes load
        // correctly regardless, because PHPUnit's own `bootstrap="vendor/autoload.php"`
        // attribute in phpunit.xml is honoured the same way whichever binary launches it.
        if (is_file($packageParatestBin)) {
            TempDir::write($root . '/vendor/bin/paratest', self::paratestShim($packageParatestBin));

            if (! @chmod($root . '/vendor/bin/paratest', 0o755)) {
                throw new RuntimeException('Cannot make ' . $root . '/vendor/bin/paratest executable.');
            }
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

    /** @see self::installVendorShim() for why `_composer_autoload_path` is set but unused by ParaTest itself. */
    private static function paratestShim(string $packageParatestBin): string
    {
        $template = <<<'PHP'
        #!/usr/bin/env php
        <?php

        declare(strict_types=1);

        $GLOBALS['_composer_autoload_path'] = __DIR__ . '/../autoload.php';

        require '__PACKAGE_PARATEST_BIN__';

        PHP;

        return str_replace('__PACKAGE_PARATEST_BIN__', $packageParatestBin, $template);
    }
}
