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
`Manuglopez\Replay` (owner's instruction; SPEC §1 named a different vendor namespace); the CLI binary stays `phpunit-replay`.

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

## D-030 — `brianium/paratest` (v7.20.0) added as dev dependency only

Testing `--parallel` (SPEC §13) requires Paratest; users install it themselves (`suggest`). The wrapper launches `vendor/bin/paratest -c <xml> [--processes N] --passthru-php="'-d' 'pcov.enabled=1' '-d' 'pcov.directory=<root>'"` — each ini flag shell-quoted and space-joined, the syntax Paratest's `Options::parsePassthru()` re-parses. Missing binary → warning and sequential fallback.

## D-031 — Worker partials written as `runs/<id>/worker-<TEST_TOKEN>-<name>.json` when Paratest sets `TEST_TOKEN`

`RunPartial::load()` merges them generically (edges/tables/lists by union, results last-write-wins, `truncated` if any worker was). Paratest's `WrapperRunner` runs PHPUnit in-process through `bin/phpunit-wrapper.php`, so the fixture only needed a `vendor/bin/paratest` shim setting `$GLOBALS['_composer_autoload_path']`.

## D-032 — Paratest's main process bootstraps the extension during test discovery

The wrapper passes the same ini flags to that process so it finds the coverage driver and stays silent (before the fix it printed a harmless `no coverage driver` warning). `--parallel` / `-p [N]` accept a space-separated value; the argv pre-splitter in `Console\Application` peeks the next token for those two options.

## D-033 — Flip detection (quarantine) ignores transitions whose OLD cached status was failure/error

Cached failures always re-run (SPEC §6.2), so "failed, fixed, passes again" is the normal heal path, not a flip — otherwise Scenario 5 would quarantine the test it just healed. Same exclusion in `verify`'s divergence check.

## D-034 — `Quarantine::testIds()` returns only currently quarantined ids

Returns only ids with `flips >= 1` and not released; released/stable entries stay in `flaky.json` for history but do not force re-runs.

## D-035 — `Graph::pruneMissingTestFiles()` checks existence only for file-shaped `not_cacheable` entries

File paths are checked; `Class::method` ids are kept (they were silently deleted on every complete pass before).

## D-036 — `RunPipeline::verify()` does its own cached-vs-actual comparison and books divergences once

It does not hand a `Quarantine` to its `GraphUpdater`, so one event is booked once. Divergences persist in `<stateDir>/divergence.json` (`runs`, last 500 `entries`).

## D-037 — Merge of the hermeticity branch over Paratest/Laravel

`RunPartial` carries both `usesDatabase` and `notCacheable`; every partial file (including `not_cacheable.json`) goes through `RunWriter::pathFor()` so Paratest workers never collide. `ReplayState::persistInProcess()` builds `RunPartial` with named arguments after a positional-argument slip was caught. Known gap: `LaravelIntegration::augment()` rebuilt `RunPartial` positionally and dropped `notCacheable`.

## D-038 — Phase 3 gains a git-backed remote cache

Owner request (2026-09-07): teams without S3/MinIO should be able to share the cache through a
dedicated git repository (not the code repo — its graph churns on every pass and would conflict).
`GitRemoteCache` keeps a shallow mirror under the state dir, treats `objects/<month>/<k>.json` as
content-addressed files (append-only when this was decided; since result stamps they hold a variant
per non-edge digest, merged with upstream on a rejected push), publishes `graph/**` only
from CI (`remote_push=all`), and is garbage-collected by `prune --remote` (monthly shards +
orphan squash). Setup, usage and alternatives (shared folder, HTTP, CI artifacts) are documented
in the README. Contracts in docs/INTERNALS.md "Phase 3 contracts".

## D-039 — Nearest-baseline selection for git-flow (`baseline_branches`)

Owner request (2026-09-07): teams working on `develop` and releasing to `main` need feature branches to
inherit `develop`'s baseline and hotfixes to inherit `main`'s. Phase 3 adds `baseline_branches`
(ordered candidates); the baseline with the smallest `git diff --name-only <sha>..HEAD` among ancestors
wins (`Change\BaselineResolver`). `default_branch` stays as the single-candidate shorthand. Recommended
CI: `develop` and `main` both run `run --allow-ci-baseline && push --graph`; only structural drift forces
a full record. Contract in docs/INTERNALS.md "Nearest baseline".

## D-040 — `LaravelDetector::enabled()` is file/config based

`Laravel\LaravelDetector::enabled()` checks for `artisan` and `laravel` config being `auto`/`on` because the wrapper runs with the package's own autoloader where no Illuminate class is loaded. `LaravelIntegration::shouldArm()` keeps the `class_exists(\Illuminate\Container\Container::class)` check for the only place that needs it — arming the runtime trackers inside the booted PHPUnit process.

## D-041 — Summary counters all count tests

`executed = affected + uncached + quarantined` (SPEC §3.1.10 example). Each executed test is classified by its file's primary reason in the `RunList`. `--dry-run` cannot classify tests before running, so it prints `Report\DryRunSummary`: `Replay  N test files would run (a affected, u uncached, q quarantined), R tests would replay`.

## D-042 — `Selector::default()` and `RunListBuilder` accept optional extra rules

`Selector::default()` and `RunListBuilder` accept optional extra rules; `LaravelIntegration::rulesFor()` returns the Laravel rules or none, used by the wrapper, the in-process state and `explain`. Rule order: Migration, PhpEdge, TestFile, Sibling, Blade, Watch.

## D-043 — `Graph::stats()`/`status` gained a `tables` counter

`Graph::stats()`/`status` gained a `tables` counter; `LaravelIntegration::augment()` rebuilds `RunPartial` with named arguments so every field (incl. `notCacheable`) survives. Augmentation runs in every persist path (wrapper record/replay/results-only/verify and in-process) only when Laravel is enabled for the project.

## D-044 — The environment goes into the content key, not into a deeper object path

`docs/proposals/remote-layout.md` is the full design record and is cited by section from
nineteen places in `src/` and `tests/`; this entry is the short form of *why*. An earlier
revision of that document would have scoped remote objects as
`objects/<generation>/<environment>/<yyyy-mm>/<k>.json`. Dropped. `Cache\ContentKey` instead folds `Fingerprint::canonicalResultEnvironment()` into its
material, covering `php` (MAJOR.MINOR) and `os`.

Three findings decided it. **The proposal's sharpest correctness argument was about data remote
objects do not carry**: it cited an unreadable coverage snapshot from another php-code-coverage
major, but an object is `{k, file, results}` and snapshots live locally at
`<stateDir>/coverage/<k>.cov`. **The local read-through mirror was collected by nothing** —
`--keep-months` existed only on `prune --remote`, which collects the remote; measured on one
populated mirror, 726 of 1,452 objects were unreachable after a single `composer.lock` change,
and the path design would not have touched that. **And the key is what makes collection a proof
rather than an estimate**: with the environment in the address, every object a machine can
address is by construction from its own environment, so one recorded elsewhere is permanently
unaddressable rather than merely absent from today's graph.

`driver` and `coverage` stay out of the address deliberately: a coverage driver changes which
lines are *reported*, not whether an assertion passed, and this package's own scripts alternate
them (`composer test` is pcov, `composer test:xdebug`). `php` and `os` remain in the
environmental bucket as well — an adopted graph still matches structurally so its edges are
inherited, while its results carry addresses this machine will never compute, and
`environmentalDrift()` → `clearResults()` is the only thing that removes them. No
`SCHEMA_VERSION` bump: nothing compares a stored key against a fresh one to decide
replayability, so the local cost of every address moving is one skipped flip check per test.

Contracts in docs/INTERNALS.md ("Cache\Fingerprint", "Commands", "GitRemoteCache").

## D-045 — The cache MAY live in the code repository, on an orphan branch; it is still not the recommendation

D-038 rejected the code repo outright, reasoning that "its graph churns on every pass and would
conflict". That reason does not distinguish the two shapes: `GitRemoteCache` reconciles a
rejected push the same way wherever the branch lives. Re-examined, and verified by execution
rather than by reading: pointing `remote` at the project's own URL with `remote_branch` set to a
name that does not exist upstream works from the first push. `establishMirror()`'s clone fails,
`initMirror()` runs `git init -b <branch>`, and the push creates the branch with **no common
ancestor** with `main` and none of the project's files in its tree. A cache-only consumer's
`--single-branch` clone fetches none of the project's history.

That shape removes the two steps teams actually stall on — creating a repository and granting
read access — and needs no deploy key, since a CI job can write with the token it already has.

**The measured objection is different from D-038's and stronger: a plain `git clone` of the
project fetches every branch, so the cache lands in the clone of everyone who checks the project
out, including people who never use this package.** Not opt-in, and bounded only if
`prune --remote --squash` actually runs. So the recommendation stays a dedicated repository, and
the same-repo orphan branch is documented as the low-setup option with that cost stated. Nothing
in the code needs to change for it: `remote_branch` has always existed, and no guard rejects a
remote URL equal to `origin`.

`remote:init --same-repo` implements this shape, and prints the clone-size cost in its own
output rather than leaving it in a document — a setup command that hides the one reason not to
use what it just configured is worse than no command.

## D-046 — A language file change selects by translation key, recorded by decorating `Translator::get()`

Until 0.13, `lang/**` and `resources/lang/**` were watch patterns, so any edit to a translation
ran every test. Measured on a real project's PR (emasex #1005): 12,673 of 12,686 tests. The
files are not executable dependencies in the sense coverage understands: a JSON file is never
run, and a PHP group file is `require`d by `FileLoader` once per translator, so coverage credits
it to every test that loads *any* key of the group. A per-file edge can only ever give back the
broad rule, and it is the edge, not the rule, that holds the cost: `PhpEdgeRule` and
`Cache\ContentKey` both read it.

**Why by key and not by file.** What a test depends on is the string it asks for, and a diff of a
language file says exactly which strings changed: `LangFiles::changedKeys()` flattens the file at
the change set's base and in the working tree and returns the keys added, deleted or given
another value. A test reads a small part of a project's keys. Reordering, re-indenting and
comments change none, so they select nothing.
A key matches itself, the group or subtree that holds it and the keys under it
(`LangFiles::matches()`, `.` as the separator, so `a.b` does not match `a.bc`), because a test
that asks for `messages.nested` or `trans('messages')` reads everything below.

**Why decorate `get()`.** `__()`, `trans()`, `trans_choice()`, `Lang::get()`, `@lang`, `choice()`,
`has()`, `hasForLocale()` and the validator's messages all end in `Translator::get()`, so one
override sees every lookup, with the key as the test spelled it. The alternatives see less:
the **loader** is asked for a locale, a group and a namespace, never for a key, so it can say
which file was read but not which line, which is the per-file answer again; and Laravel
dispatches **no event** for a resolved key (there is only the `handleMissingKeysUsing()` callback,
for a key that is missing, and only when one is registered). `TranslationTracker::arm()` does not resolve the translator itself: it asks the
container to `extend('translator', …)`, so the replacement is built when the application first
needs it, in the order it always would have been, and the container's shared instance is the
recording one for every consumer. `RecordingTranslator` copies the whole state of the original
(loader, locale, fallback, loaded lines, parsed keys, the `determineLocalesUsing` callback, the
missing-key callbacks) and is only built for exactly `Illuminate\Translation\Translator`: a
subclass may override what `get()` does, and a replacement we cannot decorate is something we
cannot see, so it marks the test `*`. What a test does to the translator afterwards is checked
when it ends (`TranslationTracker::verify()`, from `beforeApplicationDestroyed()`): `Lang::swap()`,
a mock or `instance('translator', …)` also mark it `*`. The package stays free of `illuminate/*`:
the class is autoloaded only after `class_exists(Translator::class)`.

**Why every locale of the chain is recorded.** A lookup for `es` is served from `en` when the
`es` file lacks the key (`fallback_locale`, and whatever `determineLocalesUsing()` adds:
`localeArray()`). The text the test sees then depends on the `en` line, so a test that asked for
`es` must be selected when only `en` changes. Recording only the requested locale is a false
green; recording the chain costs one extra entry per lookup, and the over-selection it causes
(an `es` test selected by an `en` change to a key it did resolve in `es`) is the price of not
guessing which locale served it. Entries are `<locale>:<key>`, split at the **first** `:` because
a locale never contains one and a key may (`pkg::messages.title`).

**Why the analysis of PHP language files is static, and never executes anything.** The selection
runs in the wrapper, in the developer's or CI's process, against two versions of a file, one of
which only exists as `git show` output. Running a project file there means running project code
outside the application that was written to run it (`env()`, a helper, a `__()` call, a database
read), and the base version cannot be `require`d without writing it somewhere first. So a file
is read with `nikic/php-parser` and accepted only when it is `return <array literal>;` (after an
optional `declare(...)`) whose keys and leaves `ConstExprEvaluator` can evaluate from literals:
no function, constant, variable, `include` or interpolation. Anything else, a syntax error
included, is not comparable. Two entries that flatten to one key (`'a.b'` and `'a' => ['b']`) are
not comparable either: which one wins depends on PHP, not on the file.

**Why what cannot be compared falls back to the broad rule for that file only.** The failure
mode that matters is a false green, a changed string that a test depends on and does not run,
so narrowing is only claimed where it can be shown: a vendor package's translations
(`lang/vendor/**`), a file under a language root that is not a translation file, a file that does
not flatten, one created or deleted since the base, no base, or a graph that recorded no key yet
select every test (`ResiduePatterns::targetsFor()`), plus every test file that resolved any
translation, wherever it lives. The same applies to the tests recorded as `*`, for any key change.
It is the previous behaviour, applied to the one file that earns it: the other language files of
the same change stay narrowed, which is why this is not an all-or-nothing switch on the whole
change. `translations => 'conservative'` is the all-or-nothing version, for a project whose
translations the tracker cannot see.

**What that costs elsewhere, accepted.** A coverage edge to a language file defeats the rule, so
`LaravelIntegration::augment()` drops it before it reaches the graph, except for a PHP group file
a test loaded without its translator resolving any key of that group (it reads the file some
other way, and keeps today's per-file behaviour for it). `Select\NonEdgeInputs` mirrors the rule
(`trans@1`, `lang@1`, `lang:uncomparable@1`, version `n5`) so a test's digest moves exactly when
the rule selects it: a digest that moved for a test the rule left out would stop serving a result
for nothing, and one that did not move for a test it selected would serve a stale one. The
structural fingerprint gains `translations`, because a graph recorded without the keys reads "no
entry" as "used no translation": one full re-record after updating, and on a mode switch. There is
no environment override, like `migrations`. Known limits, not detected: keys resolved in a
provider's `register()` before arming, values cached in a static between tests (the union across
recordings converges), reads through the loader itself.

Contracts in docs/INTERNALS.md ("Laravel", "Cache\Fingerprint"), measured effect in CHANGELOG
"Unreleased".
