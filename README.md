# phpunit-replay

Composer package: `manuglopez/phpunit-replay`.

`phpunit-replay` is a Test Impact Analysis and result-replay tool for plain PHPUnit (11.5+ and
12), with no dependency on Pest. It records, per test file, which source files were exercised
while that file's tests ran — using pcov or Xdebug as a raw coverage driver — together with each
test's own result (status, assertion count, message, duration). On a later run it compares the
working tree against a stored baseline (a git commit plus normalized content hashes) to work out
which test files are affected by what changed, executes only those, and replays the rest as
passes carrying their real, previously recorded assertion counts. Reported test and assertion
totals stay accurate even though most of the suite did not actually run.

## Status

This is v0.1. Phase 1 (filtered mode) and phase 2 (fidelity and ergonomics) are both done — this
is what exists today:

- **Phase 1**: filtered mode, and the `run`, `record`, `status`, and `baseline-path` commands.
- **Phase 2**: the in-process replay trait (`Replayable`/`ReplayableTestCase`), the `explain` and
  `prune` commands, `verify` and its divergence metric, hermeticity (`#[NotCacheable]`,
  `never_cache` globs, automatic quarantine), the Laravel integration (table/Blade tracking,
  migration/sibling/Blade-aware selection), and Paratest support.

Still planned, **phase 3**: the remote cache (filesystem, HTTP, or a dedicated git repository),
`push`/`pull`, replaying by content key across machines, `baseline_branches` nearest-baseline
selection for git-flow branching, coverage-report merging, and example GitHub Actions workflows.

Do not rely on planned features; check `CHANGELOG.md` for what has actually shipped.

## How it compares

| | Pest 5 TIA | phpunit-tia (`jasonmccreary`/`gosuperscript`) | phpunit-replay |
|---|---|---|---|
| Runner | Pest only (aborts on plain PHPUnit test classes) | PHPUnit 12/13 | PHPUnit 11.5+ and 12 |
| Unaffected tests | Synthetic pass, real assertion count | **Skipped** | Filtered mode: not loaded at all. In-process mode: synthetic pass with the real assertion count |
| Full summary/JUnit | Yes | No | Yes — the wrapper merges cached results into its summary line and, on request, into JUnit |
| Cosmetic-only changes ignored | Yes (tokenizer) | Partial | Yes (tokenizer-based content hash) |
| Per-branch baselines | Yes | No | Yes |
| Remote cache | GitHub Actions artifact via `gh` | No | Content-addressed, backend-agnostic (filesystem, HTTP/S3, or a dedicated git repo) — planned, phase 3 |
| Non-hermetic test detection | No | No | `#[NotCacheable]`, `never_cache` globs, automatic quarantine on a pass/fail flip |
| Laravel awareness (DB tables, Blade) | Yes | No | Yes, optional, autodetected |
| Parallel (Paratest) | Yes, built in | No | Yes, via `--parallel`/`-p` |

The distinction that matters most: an unaffected test in `phpunit-tia` is reported as **skipped**,
losing the assertion count and silently hiding a test that would otherwise never run again. In
`phpunit-replay` it either never enters the run at all (filtered mode) or is reported as a pass
with the assertion count it actually produced last time (in-process mode) — the summary reflects
what last really happened, not a gap.

No code from `jasonmccreary/phpunit-tia` or `gosuperscript/phpunit-tia` was copied; roughly 60% of
Pest's own TIA engine, the framework-agnostic part, was ported by copy under its MIT license — see
[Attribution](#attribution).

## Requirements

- PHP ^8.2
- PHPUnit ^11.5 or ^12
- A git repository with at least one commit (baselines and diffs are computed against git history)
- `ext-pcov` or Xdebug with `xdebug.mode=coverage`, to record the dependency graph. Without
  either, phpunit-replay disables itself with a warning and PHPUnit runs exactly as it would
  without the package.
- No `php.ini` changes are needed for pcov: the wrapper enables it per invocation with
  `-d pcov.enabled=1 -d pcov.directory=<project root>`.

## Installation

```bash
composer require --dev manuglopez/phpunit-replay
```

## Quick start

```bash
vendor/bin/phpunit-replay record   # once, records a baseline
```
```
Replay  ● recorded 1240 tests in 42 test files · 318 source files · 3120 edges · graph.json 210 KB · baseline main@a1b2c3d · 4m12s
```

Run again with nothing changed: everything replays. Change a class several test files exercise and
run again: only those tests execute, the rest still replays from the baseline.

```
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@abc1234
Replay  ✓ 31 executed (31 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@abc1234
```

See [Summary line](#summary-line) for what each number means. `run` is the default subcommand, so
`vendor/bin/phpunit-replay` and `vendor/bin/phpunit-replay run` are the same thing; anything after
a literal `--` (`vendor/bin/phpunit-replay -- --testdox`) is passed straight through to PHPUnit.

`vendor/bin/phpunit-replay status` shows the current baseline; `vendor/bin/phpunit-replay
baseline-path` prints the state directory path, useful for CI (see [CI in two
lanes](#ci-in-two-lanes)).

## Two modes

### Filtered mode (default)

This is the mode used unless you opt into in-process mode, and needs no changes to your test code.
The wrapper resolves the affected test files (see [How selection works](#how-selection-works)),
then builds `.phpunit-replay.xml` next to your real configuration: a copy of your
`phpunit.xml`/`phpunit.xml.dist` with `<testsuites>` replaced by a single `<testsuite>` listing one
`<file>` per test file to run (`<source>`, `<php>`, `<extensions>`, bootstrap kept verbatim, the
`ReplayExtension` bootstrap injected if not already registered). PHPUnit runs against that file
with `--no-coverage` (the raw pcov/Xdebug driver, not PHPUnit's own, is what records edges), and
the generated file is deleted once the run finishes (`PHPUNIT_REPLAY_KEEP_RUN=1` keeps it for
inspection). Add `.phpunit-replay.xml` to your `.gitignore`.

Passing a PHPUnit selection option — `--filter`, `--group`, `--exclude-group`, `--testsuite`, an
explicit path, `--covers`, `--uses` — disables the selection logic for that run: PHPUnit runs
exactly what you asked for, and the extension only refreshes results for the tests that ran.

### In-process mode

For situations where PHPUnit needs to see the whole suite regardless of the wrapper — an IDE
launching `phpunit` directly, `--coverage-html`, or simply not wanting the wrapper in the loop.
Extend `Manuglopez\Replay\PHPUnit\ReplayableTestCase` instead of `PHPUnit\Framework\TestCase`, or
add the trait to your own base `TestCase`:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Manuglopez\Replay\PHPUnit\Replayable;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isReplaying()) { return; } // optional: skip expensive boot work too
    }
}
```

and register the extension in `phpunit.xml`: `<extensions><bootstrap
class="Manuglopez\Replay\PHPUnit\ReplayExtension"><parameter name="mode" value="auto"/>
</bootstrap></extensions>` (`mode`: `auto|record|replay|off`).

`setUp()` **always runs**, for every test, replayed or not — only the code guarded behind
`isReplaying()` is skipped. The trait hooks the test method itself, not `setUp()`.
`TestCase::runTest()` is `private` on both PHPUnit versions, so the hook differs: **PHPUnit 12**
declares `protected function invokeTestMethod()`, which the trait overrides to apply the decision
instead of calling the real method; **PHPUnit 11.5** has no such hook, so a `#[Before]` method
swaps the private `TestCase::$methodName` property, via reflection, to an internal stub that
applies the decision and restores the property (`PHPUNIT_REPLAY_LEGACY_HOOK=1` forces this
fallback on PHPUnit 12 too, for test coverage of both paths).

Never replayed, in-process or filtered: a `#[Depends]` provider for another test (a replayed
provider would hand its dependents a `null` return value, so such a test is never marked
replayable), a cached failure or error (always reruns), a test unknown to the graph (new), a test
marked `#[NotCacheable]` or matched by `never_cache`, and a quarantined test (see
[Hermeticity](#hermeticity)).

After the run, the extension prints its own summary line below PHPUnit's own, same format as the
wrapper: `Replay  ✓ 1 executed (0 affected, 1 uncached) · 34 replayed · 0 quarantined · baseline
main@a1b2c3d`.

## Parallel

Add `--parallel`/`-p` to `run` (the default command) or `record` to run the same filtered
configuration through [Paratest](https://github.com/paratestphp/paratest) instead of a single
`vendor/bin/phpunit` process:

```sh
phpunit-replay --parallel          # Paratest's own auto-detected process count
phpunit-replay -p 4                # 4 worker processes
phpunit-replay record -p 4         # a full parallel recording pass
```

Paratest is an optional `require-dev` dependency (`brianium/paratest`). When `--parallel`/`-p` is
given but `vendor/bin/paratest` isn't installed, the wrapper warns on stderr and falls back to a
sequential PHPUnit run rather than failing.

Each worker writes its own `runs/<run-id>/worker-<TEST_TOKEN>-*.json` partial; the wrapper merges
them back together before updating the graph (edges by union, results last-write-wins, everything
else by union), so the baseline and summary line are the same regardless of process count. The
coverage driver's ini flags travel to Paratest's workers via `--passthru-php` (Paratest's own
main process is never instrumented).

## How selection works

**Changed files** are computed by diffing against the recorded baseline sha (it must be an
ancestor of `HEAD`, or a fresh recording is forced), unioned with the current working-tree status
(staged, unstaged, and untracked files, minus anything `git check-ignore` would exclude). Two more
filters narrow that set:

- a **content-hash filter**: a file is dropped if its normalized content hash is unchanged from
  the baseline commit — comments and whitespace-only edits to `.php` files (tokenizer-based),
  Blade comments/whitespace, and JS/TS/Vue/Svelte comment/whitespace edits are all ignored this
  way;
- the **last-run snapshot**: a dirty file already accounted for in the previous run is dropped
  again (touching the same uncommitted change twice doesn't re-run its tests), but a file that got
  reverted is picked back up.

**Selection rules** then run in order, each consuming what earlier rules didn't claim (the
Laravel-only ones are no-ops on a non-Laravel project, see [Laravel](#laravel)):

1. **MigrationRule** (Laravel) — a changed `database/migrations/**/*.php` file has its tables
   extracted and intersected against every test file's recorded tables.
2. **PhpEdgeRule** — a changed (or deleted) file that has an id in the graph affects every test
   file whose recorded edges include it.
3. **TestFileRule** — a changed file that matches PHPUnit's own notion of a test file (the
   directories/suffixes from `<testsuites>`) and still exists on disk affects itself.
4. **SiblingRule** (Laravel) — a new/unknown `.php` file under a provider/listener/event/observer/
   policy/console-command/factory/seeder directory affects tests with an edge to another file in
   the same directory.
5. **BladeRule** (Laravel) — a changed `.blade.php` file unknown to the graph is walked through its
   static references up to a Blade file the graph knows; tests with an edge to that ancestor are
   affected.
6. **WatchRule** — whatever is left and unknown to the graph is matched against glob → test
   directory patterns: built-in generic defaults (`.env*`, `phpunit.xml*`, `docker-compose*.y*ml`,
   test fixtures/snapshots), framework-specific defaults when detected, and your own `watch`
   config, merged together.

On top of the rules, two more categories always run: **unknown test files** (on disk, matching
PHPUnit's test-path rules, but no edges recorded — new tests) and any cached result whose
**status must be re-run**: a failure or error always reruns; a risky/warning/notice/deprecation/
incomplete/skipped result reruns only if your PHPUnit configuration's
`--fail-on-*`/`displayDetailsOn*` settings would actually surface it.

A **fingerprint** guards against incompatible baselines: its *structural* half (`composer.lock`,
`phpunit.xml(.dist)`, `phpunit-replay.php`, the cache schema version) changing discards the whole
graph and forces a fresh recording; its *environmental* half (PHP `MAJOR.MINOR`, coverage driver,
OS family) changing keeps the edges but discards cached results, which can't be trusted across a
PHP version or driver change.

Baselines are kept **per branch**, falling back to the default branch's result for a test with no
result of its own on the current branch. One rule is deliberate: **a file no test ever executed
affects nothing** — a `README.md` or docs change is the common case.

## Commands

`run` is the default command, so `phpunit-replay` and `phpunit-replay run` are the same thing.
Anything after a literal `--`, or the first token this application doesn't recognise, is
forwarded to `vendor/bin/phpunit` untouched.

**`run [--fresh] [--no-remote] [--explain] [--dry-run] [--log-junit=FILE] [--allow-ci-baseline] [--parallel|-p[=N]] [-- <phpunit args>]`**

- `--fresh` — ignore any cached baseline and record a fresh one.
- `--no-remote` — never contact a configured remote cache (no-op today; no remote cache until
  phase 3).
- `--explain` — print which rule selected each test file, and why (same table as `explain <path>`).
- `--dry-run` — print what would run without running it; implies `--explain`.
- `--log-junit=FILE` — write a merged JUnit report to `FILE` (real results plus replayed ones,
  replayed entries marked `<property name="replayed" value="true"/>`).
- `--allow-ci-baseline` — let a run detected as CI publish a branch baseline (SPEC §12.1);
  without it, a CI run never updates the stored baseline.
- `--parallel`/`-p[=N]` — run through Paratest (see [Parallel](#parallel)).

A PHPUnit selection option (`--filter`, `--group`, `--exclude-group`, `--testsuite`, an explicit
path, `--covers`, `--uses`) disables selection for that run instead: PHPUnit runs exactly what was
asked, and only results for the tests that ran are refreshed.

**`record [--fresh] [--parallel|-p[=N]]`** — runs the full suite unconditionally and records a
fresh baseline. What CI runs on the default branch after a merge.

**`verify [-- <phpunit args>]`** — runs the full suite with the extension in record mode, keeping
the existing graph (unlike `record`, which always starts empty), and compares every new result
against what a normal replay pass would have served from the same content key. A difference in
result class (a cached pass that now fails, or the reverse) is a divergence: appended to
`divergence.json`, the test is quarantined, and the baseline is still updated as `record` would.
This is the command for the full-suite lane on `main`/nightly (see [CI in two
lanes](#ci-in-two-lanes)): `Verify  ✓ 1240 tests · 1198 would replay · 0 divergences (lifetime: 2
in 143 runs)` (`✗` when this run found a divergence, or PHPUnit itself failed).

**`status`** — prints the cached graph: root, branch, state directory, coverage driver, detected
framework, file/edge/table counts, `graph.json` size, per-branch results, fingerprint drift,
quarantined test ids (with flip counts), not-cacheable count, and lifetime `verify` divergences.

**`explain <path>`** — prints which recorded test files a change to `<path>` would affect and by
which rule, computed from the stored graph without running anything (`tests/Feature/AdTest.php
← PhpEdge  app/Services/Pricing.php`), followed by `direct dependents: N` and, for a file matching
no rule, `no recorded test executes this file`.

**`prune [--flaky] [--branches] [--all]`** — drops stale graph state without touching a live pass:
`--flaky` clears the quarantine (`flaky.json`); `--branches` removes baselines for branches git no
longer knows; `--all` deletes `graph.json`, `flaky.json`, `last-run.json`, `divergence.json`, and
`runs/`; no flag prunes deleted test files from the graph plus `--branches`.

**`baseline-path`** — prints the resolved state directory and nothing else, useful for CI to know
what to archive as a build artifact.

## Summary line

Every number in the wrapper's own summary line — printed below PHPUnit's own — counts individual
**tests**, never test files:

```
Replay  ✓ 38 executed (31 affected, 7 uncached) · 1202 replayed (14 from remote) · 2 quarantined · baseline main@a1b2c3d · saved 4m12s
```

`executed` is however many tests PHPUnit actually ran, and always equals `affected` (selected by a
rule — PhpEdge, Sibling, Blade, Migration, ...) plus `uncached` (new to the graph, or forced to
rerun) plus `quarantined` (flaky or `#[NotCacheable]`) — each executed test is classified by its
file's primary reason in the run list. `replayed` is cached results served without running them
(`(N from remote)` is phase 3 plumbing; always 0 today). `saved` sums the recorded durations of
everything replayed instead of executed.

`--dry-run` has nothing executed yet to classify, so it counts test **files** instead (`Replay  12
test files would run (9 affected, 2 uncached, 1 quarantined), 342 tests would replay`); `verify`
prints its own line — see [Commands](#commands).

## Hermeticity

Three ways a test avoids ever being replayed, plus one that happens automatically:

1. **`#[NotCacheable(reason: '...')]`** on a test class or method — read by reflection while
   recording, persisted in the graph's `not_cacheable` list. The test always executes for real,
   even when its content key is unchanged.
2. **`never_cache`** globs in `phpunit-replay.php` — any test file matching one of these patterns
   always runs (e.g. `tests/Browser/**`, or tests that hit real external services).
3. **Automatic quarantine** — whenever new results are merged, a test whose content key is
   unchanged but whose result *class* flipped (pass↔fail, pass↔error) is recorded in `flaky.json`
   (`{testId, firstSeen, flips, stable, lastKey, reason}`) and forced to run every subsequent
   pass. A cached failure/error recovering to a pass is not a flip — that's the normal heal path.
   Quarantine is released via `phpunit-replay prune --flaky` or automatically after
   `quarantine_release_after` (default 20) consecutive stable passes.

`phpunit-replay verify` is the objective metric for all of this — see [Commands](#commands);
`phpunit-replay status` shows the current quarantine list, with flip counts, and the lifetime
divergence count.

`hermeticity_heuristics` (config, off by default) is reserved for a future heuristic — flagging
tests that look non-hermetic (unfaked `Carbon`/`Faker`, HTTP without `Http::fake()`) in `status`
without quarantining them. Not implemented in this build; leave it at `false`.

## Laravel

Autodetected: enabled when `<root>/artisan` exists and the `laravel` config key
(`auto`/`on`/`off`, default `auto`) isn't `off`. The package has no `illuminate/*` dependency
itself — Laravel is reached through `class_exists()`, string class names, and duck-typed calls.

What gets tracked while recording, once the app has booted for a test file:

- **Tables** — a query listener extracts the table name(s) touched by every
  `select|insert|update|delete|with|replace` query and links them to the test file
  (`migrations`, `sqlite_*`, `pg_*`, `information_schema*` excluded).
- **Blade views** — a view composer on `'*'` links every rendered view's path as a source
  dependency of the test file, exactly like a PHP file it directly touched.
- **Migration-aware tests** — every test file using `RefreshDatabase`, `DatabaseMigrations`, or
  `DatabaseTransactions` is additionally widened, when the graph is written, to cover every table
  any migration under `database/migrations/` creates — conservative by design.

Three Laravel-only selection rules build on this — Migration, Sibling, Blade — see
[How selection works](#how-selection-works). The package's own `laravel-lite` fixture (4 Feature
tests, 3 migrations, 2 Blade views) demonstrates the effect end to end: adding a column to the
`comments` migration re-runs 3 of its 4 test files (everything using `RefreshDatabase`;
`HomePageTest`, which never touches the database, replays), and editing `welcome.blade.php`
re-runs only 1 of the 4 (`HomePageTest`, the only test that renders it) — see
`tests/Integration/LaravelLiteScenariosTest.php`.

## Configuration

An optional `phpunit-replay.php` at the project root, returning an array (all keys optional):

```php
<?php
// phpunit-replay.php
return [
    'state_dir' => null,                 // null = ~/.phpunit-replay/<project-key>
    'remote' => null,                    // planned (phase 3): 'file:///mnt/replay-cache' | 'https://cache.example.com/replay/' | a dedicated git repo URL
    'remote_token' => null,              // planned (phase 3): bearer token for the remote above
    'default_branch' => null,            // null = autodetect (origin/HEAD, init.defaultBranch, main/master)
    'watch' => [],                       // extra glob => test directory/file mappings, merged with the built-in defaults
    'never_cache' => [],                 // globs of test files that always run for real (see Hermeticity)
    'quarantine_release_after' => 20,    // stable passes needed to leave automatic quarantine
    'laravel' => 'auto',                 // 'auto'|'on'|'off'
    'junit_merge' => true,               // merge cached results into --log-junit output
    'mode' => 'auto',                    // extension mode override; leave at 'auto' unless you know why not
    'hermeticity_heuristics' => false,   // reserved for a future heuristic (see Hermeticity); not implemented yet
];
```

Environment variables always win over the config file: `PHPUNIT_REPLAY=0` disables phpunit-replay
entirely, even with the extension registered; `PHPUNIT_REPLAY_STATE_DIR`,
`PHPUNIT_REPLAY_REMOTE`/`PHPUNIT_REPLAY_REMOTE_TOKEN` (planned, phase 3), and
`PHPUNIT_REPLAY_DEFAULT_BRANCH` override the matching config key; `PHPUNIT_REPLAY_MODE` overrides
`mode` (also accepts the internal `record-subset`/`results-only` values the wrapper itself uses);
`PHPUNIT_REPLAY_DEBUG=1` prints every selection decision to stderr; `PHPUNIT_REPLAY_KEEP_RUN=1`
keeps the generated `.phpunit-replay.xml` and run partial directory for inspection;
`PHPUNIT_REPLAY_LEGACY_HOOK=1` forces in-process mode's PHPUnit 11.5 reflection fallback even on
PHPUnit 12. A few more `PHPUNIT_REPLAY_*` variables exist for internal wrapper-to-extension
communication; you shouldn't need to set them by hand.

## CI in two lanes

The recommendation is the same one Pest gives for its own TIA: **PR CI keeps running the full,
unfiltered suite** — that's your correctness gate, and it's what lets phpunit-replay's own
baseline stay trustworthy. A separate workflow on `main` records the baseline after each merge,
either with `vendor/bin/phpunit-replay record --fresh` or, to also get the divergence check (see
[Hermeticity](#hermeticity)), `vendor/bin/phpunit-replay verify`.

Optionally, add a fast lane on PRs that runs `vendor/bin/phpunit-replay run` for quick feedback,
with the full-suite job still acting as the actual gate. `status`'s lifetime divergence count is
the objective signal for when that fast lane could graduate to being the actual PR gate.

Requirements: a checkout with enough history that the baseline sha is an ancestor of `HEAD`
(`fetch-depth: 0` on GitHub Actions, or the equivalent — a shallow clone forces a fresh recording
every job); pcov or Xdebug on the runner, so affected test files can have their edges re-recorded;
and, since the remote cache (`push`/`pull`) is phase 3 and doesn't exist yet, the state directory
has to be shared between jobs by hand — upload it as a build artifact from the `main` recording
job and download it before a job that calls `run`. `vendor/bin/phpunit-replay baseline-path`
prints the directory to point those upload/download steps at, without hardcoding
`~/.phpunit-replay/...`.

## Trying it on your project

1. From the real project (not this repository): `composer config repositories.replay path
   ../phpunit-replay && composer require --dev manuglopez/phpunit-replay:@dev`.
2. `vendor/bin/phpunit-replay status` should say there is no baseline yet, and show the detected
   coverage driver, git root, default branch, and test framework.
3. `vendor/bin/phpunit-replay record` runs the whole suite once and ends with the recording
   summary: test files, source files, edges, `graph.json` size, and time taken.
4. Run `vendor/bin/phpunit-replay` again with nothing changed: 0 executed, everything replayed,
   finishing in under 2 seconds plus PHP's own bootstrap time.
5. Touch one class, then run `vendor/bin/phpunit-replay --explain --dry-run`: it lists which test
   files are affected, and by which rule, without running anything.
6. `vendor/bin/phpunit-replay verify` runs the whole suite again in record mode and reports how
   many results diverge from what a normal replay pass would have served — `0 divergences` if
   nothing has drifted.
7. If something looks wrong: `--fresh` forces a clean recording, `status` shows what state is
   currently stored, and `PHPUNIT_REPLAY_DEBUG=1 vendor/bin/phpunit-replay` prints every
   selection decision to stderr.

## Known limitations

- **Edges are file-level, not method- or line-level.** Any change to a source file re-runs every
  test file whose recorded edges include it, even if the change touched an unrelated function.
- **In-process mode still runs `setUp()` for every test** — only the code guarded behind
  `isReplaying()` is skipped, so a replayed test still pays for anything outside that guard.
- **PHPUnit 11.5 has no clean hook for in-process replay**, so it needs a `#[Before]`-phase
  reflection swap of the private `methodName` property instead of overriding `invokeTestMethod()`
  — fragile by nature, forced on PHPUnit 12 too with `PHPUNIT_REPLAY_LEGACY_HOOK=1`.
- **`#[Depends]` providers always execute for real**, in-process mode included: a replayed
  provider would hand its dependents a `null` return value, so such a test is never replayable.
- **No merged coverage report, and no remote cache, until phase 3** — `--coverage-html`/
  `--coverage-php` don't account for replayed tests, and every machine keeps its own local
  `graph.json`; sharing state between CI jobs or developers is manual until then.
- **pcov needs `pcov.directory` set explicitly** — it instruments nothing without it, even though
  `phpinfo()` shows a cwd-derived default, so the wrapper always passes it explicitly.
- State is written atomically; the wrapper never hides PHPUnit's output or changes its exit code;
  any internal failure degrades to a plain PHPUnit run with a warning, never a misleading pass.

## Attribution

Portions of this package are derived from [Pest](https://github.com/pestphp/pest)
(© Nuno Maduro, MIT license) — specifically the framework-agnostic parts of its Test Impact
Analysis engine (`src/Plugins/Tia/`). The full original license text is in
[`LICENSE-PEST.md`](LICENSE-PEST.md), and every ported file carries an `@see` docblock pointing at
its exact origin (file and commit). This project is not affiliated with, endorsed by, or
officially connected to Pest or its authors.

## License

MIT. See [`LICENSE`](LICENSE).
