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

This is v0.1. Phase 1 is **filtered mode** and the `run`, `record`, `status`, and `baseline-path`
commands — that is what exists today.

Everything below marked "planned" is design, not code, and is not part of this release:

- **Phase 2**: the in-process replay trait (`Replayable`), the standalone `explain <path>`
  and `prune` commands, hermeticity support (the `#[NotCacheable]` attribute, `never_cache`
  globs, automatic quarantine), the Laravel integration (table/Blade tracking, migration-aware
  selection), and Paratest support.
- **Phase 3**: the remote cache (`push`/`pull`, content-addressed objects), coverage-report
  merging, and the `verify` command's divergence tracking.

Do not rely on planned features; if you need one, check `CHANGELOG.md` for whether it has shipped.

## How it compares

| | Pest 5 TIA | phpunit-tia (`jasonmccreary`/`gosuperscript`) | phpunit-replay |
|---|---|---|---|
| Runner | Pest only (aborts on plain PHPUnit test classes) | PHPUnit 12/13 | PHPUnit 11.5+ and 12 |
| Unaffected tests | Synthetic pass, real assertion count | **Skipped** | Filtered mode (now): not loaded at all. In-process mode (planned): synthetic pass with the real assertion count |
| Full summary/JUnit | Yes | No | Yes — the wrapper merges cached results into its summary line and, on request, into JUnit |
| Cosmetic-only changes ignored | Yes (tokenizer) | Partial | Yes (tokenizer-based content hash) |
| Per-branch baselines | Yes | No | Yes |
| Remote cache | GitHub Actions artifact via `gh` | No | Content-addressed, backend-agnostic (filesystem, HTTP/S3) — planned, phase 3 |
| Non-hermetic test detection | No | No | `#[NotCacheable]`, globs, automatic quarantine on flip — planned, phase 2 |
| Laravel awareness (DB tables, Blade) | Yes | No | Yes, optional, autodetected — planned, phase 2 |
| Parallel (Paratest) | Yes, built in | No | Planned, phase 2 |

The distinction that matters most: an unaffected test in `phpunit-tia` is reported as **skipped**,
which loses the assertion count and can silently hide a test that would otherwise never run again.
In `phpunit-replay` an unaffected test either never enters the run at all (filtered mode, current)
or is reported as a pass with the assertion count it actually produced last time it ran
(in-process mode, planned) — the numbers in the summary reflect what last really happened, not a
gap.

No code from `jasonmccreary/phpunit-tia` or `gosuperscript/phpunit-tia` was copied. Roughly 60% of
Pest's own TIA engine (`src/Plugins/Tia/`), the part that is framework-agnostic, was ported by
copy under its MIT license — see [Attribution](#attribution).

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

Record a baseline once:

```bash
vendor/bin/phpunit-replay record
```

```
Replay  ● recorded 1240 tests in 42 test files · 318 source files · 3120 edges · graph.json 210 KB · baseline main@a1b2c3d · 4m12s
```

Run again after that, with nothing (or little) changed:

```bash
vendor/bin/phpunit-replay
```

```
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@abc1234
```

Now change a class several test files exercise and run again: only the tests that actually cover
it execute, everything else is still replayed from the baseline.

```
Replay  ✓ 31 executed (31 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@abc1234
```

Every number in that line counts individual tests, not test files: `executed` is however many
tests PHPUnit actually ran this pass, split into `affected` (selected by a rule — PhpEdge, Sibling,
Blade, Migration, ...), `uncached` (new to the graph, or forced to rerun), and `quarantined`
(flaky or `#[NotCacheable]`) — the three always add up to `executed`.

`run` is the default subcommand, so `vendor/bin/phpunit-replay` and `vendor/bin/phpunit-replay run`
are the same thing. Anything after a literal `--` is passed straight through to PHPUnit:

```bash
vendor/bin/phpunit-replay -- --testdox
```

`vendor/bin/phpunit-replay status` shows the current baseline (branch, sha, file/edge counts,
fingerprint); `vendor/bin/phpunit-replay baseline-path` prints the state directory path, which is
useful for CI (see [CI in two lanes](#ci-in-two-lanes)).

## Two modes

### Filtered mode (default)

This is the only mode phase 1 implements, and it requires no changes to your test code. The
wrapper resolves the affected test files (see [How selection works](#how-selection-works)), then
builds a temporary PHPUnit configuration: a copy of your `phpunit.xml`/`phpunit.xml.dist` with its
`<testsuites>` replaced by a single `<testsuite>` listing one `<file>` per test file to run.
Everything else — `<source>`, `<php>`, `<extensions>`, bootstrap — is kept verbatim, and the
`ReplayExtension` bootstrap is injected automatically if it isn't already registered. That file is
written next to your real configuration as `.phpunit-replay.xml` and deleted again once the run
finishes (set `PHPUNIT_REPLAY_KEEP_RUN=1` to keep it for inspection). PHPUnit itself then runs
against that generated file, with `--no-coverage` (the raw pcov/Xdebug driver, not PHPUnit's own
coverage collection, is what records edges).

Add `.phpunit-replay.xml` to your `.gitignore`.

Passing PHPUnit selection options — `--filter`, `--group`, `--exclude-group`, `--testsuite`, an
explicit path, `--covers`, `--uses` — disables the selection logic for that run: PHPUnit runs
exactly what you asked for, and the extension only refreshes results for the tests that ran
(edges and the baseline sha are left untouched).

### Parallel (Paratest)

Add `--parallel`/`-p` to `run` (the default command) or `record` to run the same filtered
configuration through [Paratest](https://github.com/paratestphp/paratest) instead of a single
`vendor/bin/phpunit` process:

```sh
phpunit-replay --parallel          # Paratest's own auto-detected process count
phpunit-replay -p 4                # 4 worker processes
phpunit-replay record -p 4         # a full parallel recording pass
```

Paratest is an optional `require-dev` dependency (`brianium/paratest`). When `--parallel`/`-p`
is given but `vendor/bin/paratest` isn't installed, the wrapper warns on stderr and falls back
to a sequential PHPUnit run rather than failing.

Each worker writes its own `runs/<run-id>/worker-<TEST_TOKEN>-*.json` partial instead of a
single one; the wrapper merges them back together before updating the graph — edges by union,
results last-write-wins, everything else (tables, database usage) by union — so the recorded
baseline and the summary line are the same regardless of how many processes ran it. The
coverage driver's ini flags travel to Paratest's own worker processes via `--passthru-php`
(Paratest's own process is never instrumented).

### In-process mode (planned, phase 2)

For situations where PHPUnit needs to see the whole suite regardless — an IDE launching `phpunit`
directly, `--coverage-html`, or simply not wanting the wrapper in the loop — the design calls for
a trait on your base `TestCase` plus the extension registered in `phpunit.xml`:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Manuglopez\Replay\PHPUnit\Replayable;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isReplaying()) { return; } // optional: skip expensive boot work too
        // ...app boot, RefreshDatabase, etc.
    }
}
```

```xml
<extensions>
    <bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension">
        <parameter name="mode" value="auto"/>          <!-- auto|record|replay|off -->
        <parameter name="stateDir" value=""/>          <!-- empty = ~/.phpunit-replay/<key> -->
        <parameter name="remote" value=""/>            <!-- file:///mnt/cache | https://cache.example/replay/ -->
    </bootstrap>
</extensions>
```

None of this exists yet. The trait would override `runTest()` to short-circuit an affected-free
test with its cached assertion count; see [Known limitations](#known-limitations) for why that is
harder than it sounds on plain PHPUnit.

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

**Selection rules** then run in order, each consuming what earlier rules didn't claim:

1. **PhpEdgeRule** — a changed (or deleted) file that has an id in the graph affects every test
   file whose recorded edges include it.
2. **TestFileRule** — a changed file that matches PHPUnit's own notion of a test file (the
   directories/suffixes from `<testsuites>`) and still exists on disk affects itself.
3. **WatchRule** — whatever is left and unknown to the graph is matched against glob → test
   directory patterns: built-in generic defaults (`.env*`, `phpunit.xml*`, `docker-compose*.y*ml`,
   test fixtures/snapshots), framework-specific defaults when detected, and your own `watch`
   config, merged together.

(Phase 2 adds Laravel-specific rules ahead of these — migrations mapped to the DB tables a test
touches, and Blade/sibling-file rules — but none of that exists yet.)

On top of whatever the rules select, two more categories always run: **unknown test files** —
files that exist on disk, match PHPUnit's test-path rules, but have no edges recorded in the graph
at all (new tests) — and any cached result whose **status must be re-run**: a failure or error
always reruns; a risky/warning/notice/deprecation/incomplete/skipped result reruns only if your
PHPUnit configuration's `--fail-on-*`/`displayDetailsOn*` settings would actually surface it.

A **fingerprint** guards against comparing incompatible baselines. Its *structural* half
(`composer.lock`, `phpunit.xml`, `phpunit.xml.dist`, `phpunit-replay.php`, the cache schema
version — only for files git actually tracks) changing discards the whole graph and forces a
fresh recording. Its *environmental* half (PHP `MAJOR.MINOR`, coverage driver, OS family) changing
keeps the recorded edges but discards cached results, since a result recorded under a different
PHP version or driver can't be trusted without re-running.

Baselines are kept **per branch**: a branch without its own recorded result for a given test falls
back to the default branch's result for that test.

One rule is deliberate and worth calling out: **a file that no test ever executed affects
nothing**. If a changed file isn't in the graph's edges and doesn't match any watch pattern,
nothing runs because of it — a `README.md` or docs change is the common case.

## Configuration

An optional `phpunit-replay.php` at the project root, returning an array (all keys optional):

```php
<?php
// phpunit-replay.php
return [
    'state_dir' => null,                 // null = ~/.phpunit-replay/<project-key>
    'remote' => null,                    // planned (phase 3): 'file:///mnt/replay-cache' | 'https://cache.example.com/replay/'
    'remote_token' => null,              // planned (phase 3): bearer token for the remote above
    'default_branch' => null,            // null = autodetect (origin/HEAD, init.defaultBranch, main/master)
    'watch' => [],                       // extra glob => test directory/file mappings, merged with the built-in defaults
    'never_cache' => [],                 // planned (phase 2): globs of test files that always run
    'quarantine_release_after' => 20,    // planned (phase 2): stable passes needed to leave automatic quarantine
    'laravel' => 'auto',                 // planned (phase 2): 'auto'|'on'|'off'
    'junit_merge' => true,               // merge cached results into --log-junit output
    'mode' => 'auto',                    // extension mode override; leave at 'auto' unless you know why not
    'hermeticity_heuristics' => false,   // planned (phase 2): flag suspicious tests (Carbon/Faker/unfaked HTTP) in `status`
];
```

Environment variables always win over the config file:

- `PHPUNIT_REPLAY=0` — disables phpunit-replay entirely, even with the extension registered.
- `PHPUNIT_REPLAY_STATE_DIR` — overrides `state_dir`.
- `PHPUNIT_REPLAY_REMOTE` — overrides `remote` (planned, phase 3).
- `PHPUNIT_REPLAY_DEBUG=1` — prints every selection decision (root, branch, baseline sha, driver,
  fingerprint drift, changed files, rule matches, the final run list) to stderr.

A few more `PHPUNIT_REPLAY_*` variables exist for internal wrapper-to-extension communication
(mode, run id, project root); you shouldn't need to set them by hand.

## CI in two lanes

The recommendation is the same one Pest gives for its own TIA: **PR CI keeps running the full,
unfiltered suite** — that's your correctness gate, and it's what lets phpunit-replay's own
baseline stay trustworthy. A separate workflow on `main` records the baseline after each merge:

```bash
vendor/bin/phpunit-replay record --fresh
```

Optionally, add a fast lane on PRs that runs `vendor/bin/phpunit-replay run` for quick feedback,
with the full-suite job still acting as the actual gate.

Requirements:

- Checkout with enough history that the baseline sha is an ancestor of `HEAD` — `fetch-depth: 0`
  on GitHub Actions, or the equivalent elsewhere. A shallow clone forces a fresh recording on
  every job.
- pcov (or Xdebug in coverage mode) available on the runner, so affected test files can have their
  edges re-recorded.
- The remote cache (`push`/`pull`) is phase 3 and doesn't exist yet, so today the state directory
  has to be shared between jobs by hand — for example, upload it as a build artifact from the
  `main` recording job and download it before a job that calls `vendor/bin/phpunit-replay run`.
  `vendor/bin/phpunit-replay baseline-path` prints the directory to point your artifact
  upload/download steps at, without hardcoding `~/.phpunit-replay/...`.

## Trying it on your project

1. From the real project (not this repository), point Composer at a local path and require the
   dev branch:

   ```bash
   composer config repositories.replay path ../phpunit-replay && composer require --dev manuglopez/phpunit-replay:@dev
   ```

2. `vendor/bin/phpunit-replay status` should say there is no baseline yet, and show the detected
   coverage driver, the git root, the default branch, and the detected test framework.
3. `vendor/bin/phpunit-replay record` runs the whole suite once and ends with the recording
   summary: test files, source files, edges, `graph.json` size, and time taken.
4. Run `vendor/bin/phpunit-replay` again with nothing changed: 0 executed, everything replayed,
   finishing in under 2 seconds plus PHP's own bootstrap time.
5. Touch one class, then run `vendor/bin/phpunit-replay --explain`: it lists which test files are
   affected, and by which rule.
6. `vendor/bin/phpunit-replay verify` should run everything and report 0 divergences between what
   would have been replayed and what actually happened. This command is phase 2 and is **not
   implemented yet** — expect it to fail or be missing until then.
7. If something looks wrong: `vendor/bin/phpunit-replay --fresh` forces a clean recording,
   `vendor/bin/phpunit-replay status` shows what state is currently stored, and
   `PHPUNIT_REPLAY_DEBUG=1 vendor/bin/phpunit-replay` prints every selection decision to stderr.

## Known limitations

- **Edges are file-level, not method- or line-level.** Any change to a source file re-runs every
  test file whose recorded edges include it, even if the change touched an unrelated function.
- **`#[Depends]` is never replayed**, in-process mode included. A test that is itself a dependency
  of another test always executes for real; the default policy is to never mark such a test
  replayable in the first place, rather than hand a dependent test a `null` return value.
- **Non-hermetic tests aren't detected yet.** The `#[NotCacheable]` attribute, `never_cache`
  globs, and automatic quarantine on a pass/fail flip are all phase 2. Until then, a test whose
  outcome depends on something outside its recorded files (wall clock, network, external state)
  can be replayed as if it were still passing.
- **No merged coverage report until phase 3.** Running `--coverage-html`/`--coverage-php` today
  does not account for tests that were replayed instead of executed.
- **In-process replay has no clean hook to attach to.** `TestCase::runTest()` is `private` in both
  PHPUnit 11.5 and 12 (verified directly against `vendor/phpunit/phpunit`), so the planned trait
  can't simply override it. The design falls back to PHPUnit 12's `protected function
  invokeTestMethod()`, and to reflection against the private `methodName` property on 11.5 — the
  latter is fragile by nature.
- **pcov needs `pcov.directory` set explicitly.** It instruments nothing without it, even though
  `phpinfo()` shows a cwd-derived default; the wrapper always passes
  `-d pcov.directory=<project root>` for this reason.
- **State is written atomically** (temp file, then rename) — an interrupted run can't leave a
  corrupted `graph.json` behind.
- **The wrapper never hides PHPUnit's output or changes its exit code.** What you see and the exit
  status you get are PHPUnit's own.
- **Any internal failure degrades to a plain PHPUnit run with a warning on stderr** — a missing
  git binary, a corrupted cache file, an unreachable remote — never to a broken or misleading
  pass.

## Attribution

Portions of this package are derived from [Pest](https://github.com/pestphp/pest)
(© Nuno Maduro, MIT license) — specifically the framework-agnostic parts of its Test Impact
Analysis engine (`src/Plugins/Tia/`). The full original license text is in
[`LICENSE-PEST.md`](LICENSE-PEST.md), and every ported file carries an `@see` docblock pointing at
its exact origin (file and commit). This project is not affiliated with, endorsed by, or
officially connected to Pest or its authors.

## License

MIT. See [`LICENSE`](LICENSE).
