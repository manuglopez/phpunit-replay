# Phase 1 — development report

Status: **closed** (tag `v0.1.0-alpha1`). Package `manuglopez/phpunit-replay`, namespace `Manuglopez\Replay`, PHP 8.4.23, PHPUnit 12.5.34, pcov 1.0.12, Xdebug 3.5.3 (compiled locally for 8.4).

## What was built

| Area | Files | Origin |
|---|---|---|
| `Support/` Paths, AtomicFile, Json | 3 | new |
| `Cache/` ContentHash, Fingerprint, ProjectKey, StateDirectory, Graph, GraphStore, ContentKey, GraphUpdater | 8 | ported from Pest (ContentHash, Fingerprint, ProjectKey, Graph model) + new |
| `Change/` Git, ChangedFiles, LastRunTree | 3 | ported from Pest + new |
| `Record/` CoverageDriver, PcovDriver, XdebugDriver, DriverDetector, SourceScope, Recorder, ResultCollector, RunWriter, RunPartial | 9 | ported (SourceScope, Recorder, ResultCollector) + new |
| `PHPUnit/` Mode, ConfigurationReader, ConfigurationWriter, ReplayState, ReplayExtension, 18 subscribers | 23 | subscribers ported from Pest; rest new |
| `Select/` TestPaths, WatchPatterns, WatchDefaults (Php/Laravel/Symfony), Rules (PhpEdge/TestFile/Watch), Selector | 14 | ported (TestPaths, WatchPatterns, defaults) + new |
| `Report/` Summary, RecordSummary, JUnitMerger | 4 | new |
| `Console/` Application, run/record/status/baseline-path commands, RunPipeline, PhpunitProcess, ProjectLocator | 12 | new |
| `Config.php`, `Version.php`, `bin/phpunit-replay` | 3 | new |

Total: 77 files in `src/` (8116 lines), 51 test classes.

Fixture `tests/Fixtures/Projects/plain`: 5 classes (`Money`, `TaxCalculator`, `Cart`, `Discount`, `Greeter`), 7 test files (35 tests: data provider, `#[Depends]`, failure controlled by `FIXTURE_FAIL=1`, skipped test, file with only comments), variants in `plain-variants/`. `tests/Support/FixtureProject` copies the fixture to a tmp dir with `git init` and a `vendor/` shim (without `composer install`), and launches `bin/phpunit-replay` as a subprocess with an isolated `HOME`.

## Phase 1 gate

| Requirement | Result |
|---|---|
| `composer validate --strict` | OK |
| `vendor/bin/phpstan analyse` (max level, `phpVersion: 80200`) | `[OK] No errors` |
| Suite with pcov (`composer test`) | `Tests: 395, Assertions: 1200, Skipped: 3` (the 3 skips are guards for missing Xdebug) — 15.7 s |
| Suite with Xdebug (`XDEBUG_INI_DIR=… composer test:xdebug`) | `Tests: 395, Assertions: 1193, Skipped: 5` (skips = pcov guards) — 17.3 s |
| 12 scenarios §15 | 1–9 and 11 in `tests/Integration/Scenario*Test.php`; **10 (in-process) and 12 (quarantine) belong to phase 2** |
| 8 criteria §16 | `tests/Integration/AcceptanceCriteriaTest.php` (see methods below) |
| Atomic writes | `Support\AtomicFile` (tmp + rename); criterion 8 kills the wrapper with SIGKILL and checks `graph.json` |

Methods of `AcceptanceCriteriaTest`:

```
40  test_criterion_1_second_pass_with_no_changes_is_fast_and_replays_everything
57  test_criterion_2_changing_a_source_executes_exactly_its_dependents
82  test_criterion_3_a_failed_test_is_never_replayed
103  test_criterion_4_comment_only_change_executes_nothing
117  test_criterion_5_structural_change_forces_a_full_record_with_a_warning
132  test_criterion_6_filter_runs_only_what_was_asked_without_corrupting_the_graph
152  test_criterion_7_without_a_coverage_driver_phpunit_still_runs_normally
171  test_criterion_8_a_killed_wrapper_never_corrupts_state
```

## Real output on the fixture

Copy of the fixture in a tmp dir (`git init` + commit), isolated `HOME`, `php bin/phpunit-replay` as a subprocess. Full output from `scratchpad/walk.sh`:

```
$ phpunit-replay status
root:      <tmp>/proj
branch:    main (default: main)
head:      392d00e
state dir: <tmp>/home/.phpunit-replay/proj-a8138fc79c736026
driver:    pcov (loaded, enabled per run)
framework: plain

no baseline yet
[exit=0]

$ phpunit-replay record
PHPUnit 12.5.34 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.23
Configuration: <tmp>/proj/.phpunit-replay.xml

........................S..........                               35 / 35 (100%)

Time: 00:00.025, Memory: 10.00 MB

OK, but some tests were skipped!
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ● recorded 35 tests in 7 test files · 12 source files · 18 edges · graph.json 6 KB · baseline main@392d00e · 0s
[exit=0, wall=316 ms]

$ phpunit-replay 
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=176 ms]

# edited src/Money.php (behaviour change)
$ phpunit-replay --explain --dry-run
tests/CartTest.php                       ← PhpEdge  src/Money.php
tests/CommentedTest.php                  ← PhpEdge  src/Money.php
tests/DependsTest.php                    ← PhpEdge  src/Money.php
tests/DiscountTest.php                   ← PhpEdge  src/Money.php
tests/MoneyTest.php                      ← PhpEdge  src/Money.php
tests/TaxCalculatorTest.php              ← PhpEdge  src/Money.php
Replay  ✓ 0 executed (6 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@392d00e
[exit=0]

$ phpunit-replay 
PHPUnit 12.5.34 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.23
Configuration: <tmp>/proj/.phpunit-replay.xml

....................S..........                                   31 / 31 (100%)

Time: 00:00.022, Memory: 10.00 MB

OK, but some tests were skipped!
Tests: 31, Assertions: 53, Skipped: 1.
Replay  ✓ 31 executed (6 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=325 ms]

# edited src/Money.php (comments only, clean baseline)
$ phpunit-replay 
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=175 ms]

# FIXTURE_FAIL=1

<tmp>/proj/tests/GreeterTest.php:41
<pkg>/vendor/phpunit/phpunit/phpunit:104

FAILURES!
Tests: 4, Assertions: 8, Failures: 1.
[exit=1]

# failed test must re-run without changes

OK (4 tests, 8 assertions)
Replay  ✓ 4 executed (0 affected, 1 uncached) · 31 replayed · 0 quarantined · baseline main@392d00e
[exit=0]

$ phpunit-replay 
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@392d00e
[exit=0, wall=180 ms]

$ phpunit-replay status
root:      <tmp>/proj
branch:    main (default: main)
head:      392d00e
state dir: <tmp>/home/.phpunit-replay/proj-a8138fc79c736026
driver:    pcov (loaded, enabled per run)
framework: plain

files:      12
test files: 7
edges:      18
graph.json: 6 KB

results:
  main                 complete   392d00e    35 results

fingerprint:
  structural:    composer_lock=4acce0fa692f2459e2ed9e17acaeade1 phpunit_xml=9afa8142347478a26ea1c4945e534b1a phpunit_xml_dist=null replay_config=null schema=1
  environmental: driver=pcov os=Linux php=8.4

quarantined: 0
[exit=0]

$ phpunit-replay baseline-path
<tmp>/home/.phpunit-replay/proj-a8138fc79c736026
[exit=0]

# state dir contents:
total 12
drwxr-xr-x 3 mglopez mglopez  100 sep  6 22:40 .
drwxr-xr-x 3 mglopez mglopez   60 sep  6 22:40 ..
-rw-r--r-- 1 mglopez mglopez 6450 sep  6 22:40 graph.json
-rw-r--r-- 1 mglopez mglopez  100 sep  6 22:40 last-run.json
drwxr-xr-x 2 mglopez mglopez   40 sep  6 22:40 runs
# project dir after runs (no .phpunit-replay.xml left):
. .. composer.json composer.lock .git .gitignore .phpunit.cache phpunit.xml src tests vendor 
```

Reading: the first run records (35 tests, 7 test files, 12 sources, 18 edges); the second run with no changes gives 0 executed / 35 replayed in **176 ms** wall time (PHP bootstrap included); changing `src/Money.php` runs exactly the 6 test files with an edge to it (31 tests) and replays the 4 from `GreeterTest`; changing only comments runs 0; a failed test is saved and re-run without changes (`1 uncached`); `--filter` does not touch edges or sha (scenario 6).

## What was left out, and why

- **In-process mode (`Replayable` trait)** — phase 2 per spec. Also `TestCase::runTest()` is `private` in 11.5 and 12; the spike `docs/spikes/in-process-replay.md` verifies the two viable mechanisms (`invokeTestMethod()` in 12.5; reflection swap of `methodName` in 11.5/12.5).
- **`explain`, `prune`, hermeticity (`#[NotCacheable]`, globs, quarantine), `verify`, Laravel, Paratest** — phase 2. `--explain` as an option of `run` is already present.
- **Remote cache, `push`/`pull`, `CoverageMerger`** — phase 3. `--no-remote` is accepted and does nothing.
- **`generator` in graph.json** — not written yet (`Version::ID` is added in phase 2).
- **Package CI (matrix 8.2/8.3/8.4 × 11.5/12 × pcov/xdebug)** — phase 3 (`.github/workflows/ci.yml`). Locally only 8.4 + 12.5; 11.5 verified at the API level (signatures) and in the spike, not with the full suite.
- **System Xdebug** — the pacman `xdebug` package is for PHP 8.5; 3.5.3 was compiled for 8.4 in the scratchpad. `/etc/php84` has not been touched.

## Trying it on a real project

Exact steps in `README.md` → "Trying it on your project". Summary:

1. `composer config repositories.replay path ../phpunit-replay && composer require --dev manuglopez/phpunit-replay:@dev`
2. `vendor/bin/phpunit-replay status` → `no baseline yet`, driver, git root, default branch, framework.
3. `vendor/bin/phpunit-replay record` → full suite + `Replay  ● recorded …` line.
4. `vendor/bin/phpunit-replay` with no changes → `0 executed … N replayed`, < 2 s + bootstrap.
5. Touch a class → `vendor/bin/phpunit-replay --explain` (add `--dry-run` to avoid executing).
6. `verify` → phase 2 (does not exist yet).
7. If something doesn't add up: `--fresh`, `status`, `PHPUNIT_REPLAY_DEBUG=1` (decisions to stderr), `PHPUNIT_REPLAY_KEEP_RUN=1` keeps `runs/<id>/` and `.phpunit-replay.xml`.

pcov note: on the real project it's enough to have `ext-pcov` loaded; the wrapper launches PHPUnit with `-d pcov.enabled=1 -d pcov.directory=<root>`. With Xdebug, the wrapper adds `-d xdebug.mode=coverage`; the extension must be loaded via ini so the child process sees it.
