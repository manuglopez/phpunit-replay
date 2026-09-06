# Decisions log

Deviations from SPEC.md and other non-obvious choices, with reasons. Newest last.

## D-001 — pcov enabled per process, not globally

The build machine ships pcov 1.0.12 with `pcov.enabled=0` in `/etc/php84/conf.d/20-pcov.ini`
and no passwordless sudo. The wrapper already launches PHPUnit with `-d pcov.enabled=1`
(SPEC §2.4), so the package's own suite runs with `composer test`
(`php -d pcov.enabled=1 vendor/bin/phpunit`) instead of editing system ini files.
Driver detection distinguishes "extension loaded" (wrapper: can enable it) from
"loaded and enabled" (extension inside PHPUnit: can record now).

## D-002 — Xdebug matrix not run locally

Xdebug is not installed (`pacman -S xdebug` needs sudo). `XdebugDriver` is implemented
against the documented API and unit-tested with the function-existence guard; the
Xdebug integration matrix is left to CI (`.github/workflows/ci.yml`, phase 3).

## D-003 — Dev tooling

`phpstan/phpstan` (level max) and `laravel/pint` (PSR-12 preset + strict_types) as
dev dependencies. Pint chosen over raw php-cs-fixer for zero-config PSR-12.

## D-004 — `TestCase::runTest()` is private: in-process replay needs another hook

SPEC §2.2 and §6.3 assume `protected function runTest(): mixed` is overridable. Verified against
`vendor/phpunit/phpunit/src/Framework/TestCase.php` (12.5.34, line 1348) and a scratch install of
11.5.56 (line 1662): it is `private` in both. Plan for phase 2 (`Replayable` trait):

- PHPUnit 12.x exposes `protected function invokeTestMethod(string $methodName, array $testArguments): mixed`
  (12.5.34 line 1315), called from `runTest()`. The trait overrides it and short-circuits with the
  cached assertion count. PHPUnit emits `Test\CustomTestMethodInvocationUsed` when it is overridden;
  that event is informational.
- PHPUnit 11.5 has no such hook. Fallback: a `#[Before]`-phase hook in the trait swaps the private
  `methodName` property (via reflection) to a trait stub that adds the cached assertions and restores
  the property. `valueObjectForEvents()` is already cached at that point, so test ids stay intact.
  Fragile by nature; covered by an integration test per PHPUnit major and documented as a limitation.

## D-005 — `ContentHash::ofContent(string $content, string $pathForType)` argument order

Pest's original is `ofContent(string $path, string $raw)`. SPEC §4.4 defines
`ofContent(string $content, string $pathForType)`. The spec order is used; the port swaps the
parameters and is covered by the ported unit tests.

## D-006 — Xdebug for the local matrix built from source

The system `xdebug` package targets PHP 8.5 while `php` resolves to 8.4.23
(`undefined symbol: php_globfree` when loading it). Xdebug 3.5.3 is compiled for 8.4 with
`phpize84` in the session scratchpad and loaded with `-d zend_extension=<path>/xdebug.so -d xdebug.mode=coverage`
for the Xdebug run of the suite. Because the wrapper spawns child PHP processes, the extension is loaded through an extra ini scan directory (`XDEBUG_INI_DIR` containing `zend_extension=…/xdebug.so`, `xdebug.mode=coverage`, `pcov.enabled=0`) exported as `PHP_INI_SCAN_DIR`, which children inherit; `composer test:xdebug` does exactly that.

## D-007 — All graph merging lives in one service

The wrapper (filtered mode) and, in phase 2, the extension (in-process mode) both need SPEC §7.3
(replace edges, merge results, prune, recompute `k`). Rather than two implementations, the
extension writes run partials (`runs/<run-id>/*.json`) and a single `Cache\GraphUpdater` applies
them to the graph. In-process mode calls the same service at `ExecutionFinished`.

## D-008 — Package name `manuglopez/phpunit-replay`, namespace `Manuglopez\Replay`

The repository lives at `github.com/manuglopez/phpunit-replay` and the Composer package is
`manuglopez/phpunit-replay` (owner's instruction, 2026-09-06). The PHP namespace stays
`Manuglopez\Replay` (owner's instruction; SPEC §1 said `Orlegitech\Replay`); the CLI binary stays `phpunit-replay`.

## D-009 — pcov needs `pcov.directory` explicitly

pcov 1.0.12 instruments nothing when `pcov.directory` is unset even though phpinfo shows a cwd-derived value; the wrapper passes `-d pcov.directory=<root>` (SPEC §2.4 already required it) and the package's own `composer test` script passes `-d pcov.directory=.`; `FixtureProject::phpunit()` does the same. Unit test `PcovDriverTest` skips with a message when the directory is not set.

## D-010 — `Recorder::beginTest()` closes a dangling test instead of ignoring a nested begin

PHPUnit emits `Test\Finished` only when `wasPrepared()` is true (`vendor/phpunit/phpunit/src/Framework/TestRunner/TestRunner.php`, `testFinished` guarded by `wasPrepared()`), and a `setUp()` throwing `SkippedTest`/`IncompleteTest` never sets it, so the next `PreparationStarted` must flush the previous test's coverage. Deviates from Pest's Recorder.

## D-011 — `Config` carries `mode` and `hermeticity_heuristics` keys beyond SPEC §9's array example

`Config::isKnownMode()` accepts `auto|record|replay|off|record-subset|results-only` (the last two are wrapper→extension values).

## D-012 — `ConfigurationReader::fromXmlFile()` prepends `--configuration <file>` to the CLI parameters

`sebastian/cli-parser` discards element 0 as `$argv[0]`; `includeTestSuites()` (PHPUnit 12) vs `includeTestSuite()` (11.5) is bridged with try/catch instead of `method_exists` because PHPStan proves `method_exists` always true on 12.

## D-013 — `ReplayState::boot()` takes `(Mode, root, stateDir, runId, ?CoverageDriver)` rather than `(Config, Configuration)` as sketched in SPEC §6.1

The extension resolves everything from env vars set by the wrapper. In-process mode (phase 2) will add a `Config`-based boot path.

## D-014 — `TestPaths::fromConfiguration()` has no `['Test.php']` last-resort fallback

PHPUnit's `Configuration::testSuffixes()` is typed non-empty, so the branch would be dead code under PHPStan max.

## D-015 — Watch defaults for Symfony map every pattern to all test directories, same as Laravel

SPEC §7.2.6 lists both with the same `→ tests` shape.

## D-016 — `FlushOnExecutionFinished` takes a `Closure` meta provider

PHP forbids `callable` typed properties. `ResultCollector::merge()` from Pest was dropped (unused).

## D-017 — Remote pushes from automated sessions use the HTTPS remote with `gh auth git-credential` as a repo-local credential helper

The developer's SSH setup (passphrase-protected key behind the GNOME keyring agent) cannot answer prompts from a non-interactive session.

## D-018 — In-process summary line is printed on `Application\Finished`, not `TestRunner\Finished`

`vendor/phpunit/phpunit/src/TextUI/TestRunner.php:66-67` emits `testRunnerExecutionFinished()`/`testRunnerFinished()` inside `TestRunner::run()`, before `Application.php:281` prints the result; `applicationFinished()` (`Application.php:310`) is the only event after the report. Subscriber `PrintSummaryOnApplicationFinished`.

## D-019 — In-process "complete" flag = `!truncated && mode !== ResultsOnly`

The shell exit code does not exist yet at `TestRunner\ExecutionFinished` (computed at `Application.php:305`), so failing runs still publish the baseline — same outcome as the wrapper for exit 1.

## D-020 — `#[Depends]` providers are never replayed (SPEC §6.3)

A replayed provider would hand `null` to dependents (verified in docs/spikes/in-process-replay.md B5). Consequence: a suite with such providers never reaches "0 executed" in in-process mode; the wrapper's filtered mode is unaffected because it selects whole files.

## D-021 — `markReplayed()` is called for ReplaySkipped/ReplayIncomplete too

Persisted results keep the original status/time/assertions/message (SPEC §6.3 last paragraph), not the synthetic run's.

## D-022 — `PHPUnit\ReplayableTestCase` (abstract, uses the trait) exists for PHPStan `trait.unused` and as the "extend instead of compose" option

Users can extend the abstract class instead of using the trait directly.

## D-023 — `Graph::isNotCacheable()` matches both the raw and the `Paths::relative()`-normalised argument

`setNotCacheable()` stores entries through `relative()`, which rewrites `App\Tests\FooTest::testBar` into `App/Tests/FooTest::testBar`.

## D-024 — The extension registers `src/` with `PHPUnit\Util\ExcludeList::addDirectory()` at bootstrap

Stack traces of failing tests in suites using the trait do not show `Replayable.php` frames.

## D-025 — `PHPUNIT_REPLAY_MODE=replay` makes the extension return silently

The wrapper drives replay by file selection; in-process replay is chosen by the extension itself when no wrapper env is present.

## D-026 — laravel-lite fixture runs Laravel 13.30.1 / PHPUnit 12.5

`composer create-project laravel/laravel` resolves today; not Laravel 12 as SPEC-era notes assumed.
The fixture's `vendor/` is gitignored and installed with `composer install` inside `tests/Fixtures/Projects/laravel-lite` (README there). Laravel integration tests `markTestSkipped` when missing. The package itself has no `illuminate/*` dependency: Laravel classes are reached through `class_exists`/string class names/`object`-typed dynamic calls.

## D-027 — `FixtureProject::laravelLite()` copies the fixture `vendor/` instead of symlinking

PHP resolves `__DIR__`/`__FILE__` of included files to symlink-followed paths; a symlinked `vendor` rooted every autoloaded class (app's `App\` namespace included, via Laravel's `Application::inferBasePath()`) under the original fixture path, and pcov (scoped to copy root) saw almost nothing. Only `vendor/manuglopez/phpunit-replay` is recreated as an absolute symlink. `TempDir::copyTree()` recreates symlinks instead of following them (following recursed into the package itself).

## D-028 — `uses_database.json` collects test files using RefreshDatabase/DatabaseMigrations/DatabaseTransactions

Collected by `Laravel\UsesDatabaseCollector` and flushed by `FlushUsesDatabaseOnExecutionFinished`, additive to existing `RunWriter`/`RunPartial` (missing file → `[]`). `LaravelIntegration::augment()` reads it to add every migration table to database-using test files (SPEC §10 MigrationTables, conservative).

## D-029 — Laravel entry points centralised in `Laravel\LaravelIntegration`

`shouldArm`, `subscribers`, `rules`, `augment` all centralised there. `ReplayExtension` arms trackers when `shouldArm($root)` is true (Container class loaded and `artisan` present). Rule wiring into `Selector::default()` and `augment()` into persist paths done in wiring step after hermeticity work lands.
