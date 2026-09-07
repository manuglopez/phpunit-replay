# Phase 2 — development report

Status: **closed** (tag `v0.1.0-beta1`). On top of phase 1 (filtered), the following are added: in-process mode (`Replayable` trait), `explain`/`prune`/`verify` commands, hermeticity (`#[NotCacheable]`, `never_cache` globs, automatic quarantine on flip, `divergence.json`), Laravel integration (tables, Blade, Migration/Sibling/Blade rules, `laravel-lite` fixture) and Paratest (`--parallel`).

## What was built

| Block | Key files | Notes |
|---|---|---|
| In-process | `PHPUnit/Replayable.php`, `ReplayableTestCase.php`, `PHPUnit/Decision/*`, `ReplayState::{bootInProcess,decide,persistInProcess}`, `Subscribers/{PersistInProcessOnExecutionFinished,PrintSummaryOnApplicationFinished}` | `runTest()` is private: `invokeTestMethod()` hook in PHPUnit 12, reflection swap in 11.5 (`PHPUNIT_REPLAY_LEGACY_HOOK=1` forces it in 12 for testing). Spike in `docs/spikes/in-process-replay.md`. |
| Shared services | `Cache/{RunContext,BaselineWriter}`, `Select/{RunList,RunListBuilder}`, `Console/ExplainFormatter` | Extracted from `RunPipeline`; wrapper and extension use the same persistence and the same run list. |
| Hermeticity | `Attributes/NotCacheable`, `Record/NotCacheableCollector`, `Hermeticity/{Policy,Quarantine,DivergenceLog}`, `Support/Glob` | Flip = same `k` key, different state class (transitions whose cached state was failure/error are excluded). `flaky.json`, `divergence.json`. |
| Commands | `Commands/{Explain,Prune,Verify}Command`, `Report/{VerifySummary,DryRunSummary}` | `verify` = full suite in record mode + comparison with what would have been replayed. |
| Laravel | `Laravel/{TableExtractor,TableTracker,BladeTracker,BladeReferences,MigrationTables,LaravelDetector,LaravelIntegration,UsesDatabaseCollector}`, `Select/Rules/{Migration,Sibling,Blade}Rule`, `Subscribers/{ArmLaravelTrackersOnPrepared,FlushUsesDatabaseOnExecutionFinished}` | No `illuminate/*` dependency in the package. Fixture `tests/Fixtures/Projects/laravel-lite` (Laravel 13.30, sqlite in-memory, 3 migrations, 2 models, 4 Feature, 3 views). |
| Paratest | `Console/Runner/ParatestProcess`, `RunWriter::pathFor`, `RunPartial::readMerged` | `brianium/paratest` 7.20 only as a dev-dep; workers write `worker-<TEST_TOKEN>-*.json`. |
| Counters | `Report/Summary`, `RunPipeline::classifyExecuted` | All in tests: `executed = affected + uncached + quarantined`. |

Total: 117 files in `src/` (12705 lines), 81 test classes.

## Phase 2 gate

| Requirement | Result |
|---|---|
| `composer validate --strict` | OK |
| `vendor/bin/phpstan analyse` (max, php 8.2) | `[OK] No errors` |
| pcov suite | `Tests: 599, Assertions: 1959, Skipped: 3` |
| Xdebug suite (`XDEBUG_INI_DIR=… composer test:xdebug`) | `Tests: 599, Assertions: 1952, Skipped: 5` |
| Scenarios §15 | 1–12 complete (`Scenario10InProcessReplayTest`, `Scenario12QuarantinedTestAlwaysRunsTest` added) |
| laravel-lite: migration → only tests for that table | `3 executed (3 affected) · 1 replayed` (the 3 with `RefreshDatabase`; `HomePageTest` replayed) — see output |
| laravel-lite: Blade view → only tests that render it | `1 executed (1 affected) · 3 replayed` — see output |
| Paratest | `record -p 2` produces the same edges as the sequential recording (`ParallelRunTest`) |

## Real output

Fixtures copied to tmp with `git init`, isolated `HOME`, `php bin/phpunit-replay` as a subprocess. Full output:

```
############ A. plain fixture: explain / NotCacheable / flaky+quarantine / verify / prune
$ phpunit-replay record
Tests: 38, Assertions: 64, Skipped: 1.
Replay  ● recorded 38 tests in 9 test files · 14 source files · 21 edges · graph.json 7 KB · baseline main@d79a6f9 · 0s

$ phpunit-replay   (unchanged; NotCacheableTest must still run)
OK (2 tests, 2 assertions)
Replay  ✓ 2 executed (0 affected, 0 uncached) · 36 replayed · 2 quarantined · baseline main@d79a6f9

$ phpunit-replay explain src/Money.php
tests/CartTest.php                       ← PhpEdge  src/Money.php
tests/CommentedTest.php                  ← PhpEdge  src/Money.php
tests/DependsTest.php                    ← PhpEdge  src/Money.php
tests/DiscountTest.php                   ← PhpEdge  src/Money.php
tests/MoneyTest.php                      ← PhpEdge  src/Money.php
tests/TaxCalculatorTest.php              ← PhpEdge  src/Money.php

direct dependents: 6

$ phpunit-replay explain README.md

direct dependents: 0
no recorded test executes this file

$ phpunit-replay verify
Tests: 38, Assertions: 64, Skipped: 1.
Verify  ✓ 38 tests · 36 would replay · 0 divergences (lifetime: 0 in 1 runs)

$ FIXTURE_FLIP=1 phpunit-replay verify
Tests: 38, Assertions: 64, Failures: 1, Skipped: 1.
Verify  ✗ 38 tests · 36 would replay · 1 divergences (lifetime: 1 in 2 runs)
[exit=]

$ phpunit-replay   (FlakyTest quarantined → always runs)
Replay  ✓ 3 executed (0 affected, 1 uncached) · 35 replayed · 2 quarantined · baseline main@d79a6f9

$ phpunit-replay status
files:      14
test files: 9
edges:      21
tables:     0
graph.json: 7 KB

results:
  main                 complete   d79a6f9    38 results

fingerprint:
  structural:    composer_lock=4acce0fa692f2459e2ed9e17acaeade1 phpunit_xml=9afa8142347478a26ea1c4945e534b1a phpunit_xml_dist=null replay_config=null schema=1
  environmental: driver=pcov os=Linux php=8.4

quarantined: 1
  App\Tests\FlakyTest::testDependsOnAnExternalFlag  flips=1 stable=0 reason=divergence
not cacheable: 1 (1 files, 0 ids)
divergences: 1 in 2 verify runs

$ phpunit-replay prune --flaky
quarantine cleared (1 entries)

$ phpunit-replay
Replay  ✓ 2 executed (0 affected, 0 uncached) · 36 replayed · 2 quarantined · baseline main@d79a6f9

############ B. in-process fixture (trait Replayable, no wrapper)
$ php -d pcov.enabled=1 -d pcov.directory=$PWD vendor/bin/phpunit    (first run)
OK, but some tests were skipped!
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ● recorded 35 tests in 7 test files · 13 source files · 25 edges · graph.json 6 KB · baseline main@5556b9b · 0s
setup-count=35

$ ... vendor/bin/phpunit    (second run, unchanged)
OK, but some tests were skipped!
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ✓ 1 executed (0 affected, 1 uncached) · 34 replayed · 0 quarantined · baseline main@5556b9b
setup-count=1

$ PHPUNIT_REPLAY_LEGACY_HOOK=1 ... vendor/bin/phpunit    (reflection path)
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ✓ 1 executed (0 affected, 1 uncached) · 34 replayed · 0 quarantined · baseline main@5556b9b

############ C. paratest
$ phpunit-replay record -p 2
OK, but some tests were skipped!
Tests: 38, Assertions: 64, Skipped: 1.
Replay  ● recorded 38 tests in 9 test files · 14 source files · 21 edges · graph.json 7 KB · baseline main@d79a6f9 · 1s

$ phpunit-replay -p 2
Replay  ✓ 2 executed (0 affected, 0 uncached) · 36 replayed · 2 quarantined · baseline main@d79a6f9

############ D. laravel-lite
$ phpunit-replay status | head -6
root:      <tmp>/laravel
branch:    main (default: main)
head:      9c350e4
state dir: <tmp>/home/.phpunit-replay/laravel-7583c52992ac0845
driver:    pcov (loaded, enabled per run)
framework: laravel

$ phpunit-replay record
[30;42mOK (4 tests, 8 assertions)[0m
Replay  ● recorded 4 tests in 4 test files · 27 source files · 67 edges · graph.json 2 KB · baseline main@9c350e4 · 0s

$ phpunit-replay
Replay  ✓ 0 executed (0 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@9c350e4

# edited database/migrations/2024_01_03_000000_create_comments_table.php
$ phpunit-replay --explain --dry-run
tests/Feature/PostJsonTest.php           ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/PostsIndexTest.php         ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/UserModelTest.php          ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
Replay  3 test files would run (3 affected, 0 uncached, 0 quarantined), 1 tests would replay

$ phpunit-replay
[30;42mOK (3 tests, 6 assertions)[0m
Replay  ✓ 3 executed (3 affected, 0 uncached) · 1 replayed · 0 quarantined · baseline main@9c350e4

# edited resources/views/welcome.blade.php
$ phpunit-replay --explain --dry-run
tests/Feature/HomePageTest.php           ← PhpEdge  resources/views/welcome.blade.php
Replay  1 test files would run (1 affected, 0 uncached, 0 quarantined), 3 tests would replay

$ phpunit-replay
[30;42mOK (1 test, 2 assertions)[0m
Replay  ✓ 1 executed (1 affected, 0 uncached) · 3 replayed · 0 quarantined · baseline main@9c350e4

$ phpunit-replay explain database/migrations/2024_01_03_000000_create_comments_table.php
tests/Feature/PostJsonTest.php           ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/PostsIndexTest.php         ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)
tests/Feature/UserModelTest.php          ← Migration database/migrations/2024_01_03_000000_create_comments_table.php (comments)

direct dependents: 1
```

Readings:
- **A**: `NotCacheableTest` (2 tests) runs on every pass and is counted in the summary's `quarantined` slot (the only one the spec reserves for "always runs"); a clean `verify` gives 0 divergences; with `FIXTURE_FLIP=1` it detects 1 divergence, puts it in quarantine, and the history moves to `1 in 2 runs`; `prune --flaky` releases it.
- **B**: in-process without a wrapper: PHPUnit sees 35 tests / 61 assertions on both passes; the second run executes only `DependsTest::testFirst` (the `#[Depends]` provider, which is never replayed) and `.setup-count` goes from 35 to 1: the `isReplaying()` guard saved the expensive `setUp()` for the 34 replayed tests. The reflection path gives the same result.
- **C**: Paratest: same numbers as the sequential recording.
- **D**: Laravel: `record` traces 27 sources (Blade views included) and 3 tables; touching the `comments` migration selects exactly the 3 tests that use `RefreshDatabase` (all migration tables belong to them, spec §10) and replays `HomePageTest`; touching `welcome.blade.php` selects only `HomePageTest` via an edge (`BladeTracker` registered it when recording).

## What was left out, and why

- **Hermeticity heuristic** (`hermeticity_heuristics`, §8.4): the config key exists but does not flag "suspicious" items in `status`. Phase 3, together with the remote cache (needs edges to `Carbon/`, `Faker/`, `Http/Client`).
- **Merged coverage** (`CoverageMerger`, `--coverage-php`) — phase 3 per spec.
- **Summary label**: `#[NotCacheable]` tests are counted as `quarantined`; in phase 3 the summary will distinguish `N quarantined · M not cacheable`.
- **PHPUnit 11.5 with the full suite**: only verified at the API level and in the spike (`docs/spikes`); the real matrix is left for `.github/workflows/ci.yml` (phase 3). The laravel-lite fixture resolved PHPUnit 12.5, not 11.5.
- **Paratest in in-process mode**: not tested (Paratest + trait); the wrapper with `-p` is.

## Trying it on a real project

In addition to the 7 steps from phase 1 (README → "Trying it on your project"):

1. **In-process**: in your base `TestCase` `use \Manuglopez\Replay\PHPUnit\Replayable;` (or extend `ReplayableTestCase`), register `<extensions><bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"><parameter name="mode" value="auto"/></bootstrap></extensions>` in `phpunit.xml` and run plain `vendor/bin/phpunit` twice: the second run should print `Replay  ✓ … replayed` after the PHPUnit summary with the same number of assertions. Add `if ($this->isReplaying()) return;` after `parent::setUp()` to save the boot.
2. **Explain**: `vendor/bin/phpunit-replay explain app/Models/User.php`.
3. **Flaky**: `vendor/bin/phpunit-replay verify` on `main` (or nightly); `status` shows `divergences: N in R verify runs` and the quarantined ids; `prune --flaky` clears it.
4. **Laravel**: `status` should say `framework: laravel` and `tables: N` after `record`; touch a migration and check with `--explain --dry-run` that only the DB tests show up.
5. **Parallel**: `composer require --dev brianium/paratest` and `vendor/bin/phpunit-replay record -p`.
