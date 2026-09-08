# phpunit-replay

Run only the tests your change could possibly affect. Replay everything else as a real pass — with its real assertion count — instead of skipping it.

[Packagist](https://packagist.org/packages/manuglopez/phpunit-replay) · [CI](https://github.com/manuglopez/phpunit-replay/actions)

Composer package `manuglopez/phpunit-replay`, namespace `Manuglopez\Replay`. Plain PHPUnit 11.5+, 12, or 13, no dependency on Pest.

## The problem

A PHPUnit suite grows with the codebase, but most of it is irrelevant to any single change. If a
project has 3,000 tests and you edit one method in one class, the overwhelming majority of those
tests import code that never calls, is never called by, and shares no runtime path with what you
touched — nothing you did can change their outcome. Yet the default is to run all 3,000 of them,
every time, on every commit, in every PR. That costs CI minutes that scale with test count instead
of change size, and it costs developers a feedback loop measured in minutes when it could be
measured in seconds.

**Test Impact Analysis (TIA)** is the answer: instead of guessing from file paths or naming
conventions, record which source files a test actually *executed* the last time it ran, then use
that recorded dependency to decide, on the next run, which tests a given change could possibly
affect. Say `app/Services/Pricing.php` changes. Only test files whose last recorded run actually
executed `Pricing.php` — directly, or several calls deep — are candidates for a different result;
every other test file's outcome is provably unchanged, because its last run never touched that
code. A `README.md` edit, a comment, or a config file no test ever reads through affects nothing at
all — the common case in most commits.

## How phpunit-replay solves it

Three ideas, in the order they run:

**(a) Record.** While the suite runs once with pcov or Xdebug active as a raw coverage driver
(not PHPUnit's own `--coverage-*`, which stays off during recording), phpunit-replay watches which
source files execute while each test *file*'s tests run, and reduces that to a dependency edge:
`test file → source file`. It also records each individual test's own result — status, assertion
count, message, duration — keyed by test id. Both are written to a single `graph.json`.

**(b) Detect what changed, select what could differ.** On a later run, phpunit-replay diffs the
working tree against the git commit the graph was recorded at, plus anything currently
staged/unstaged/untracked. A **content hash that ignores comments and whitespace** (tokenizer-based
for `.php`, similar normalization for Blade/JS/TS) drops cosmetic-only edits from that diff before
selection ever runs — a renamed variable re-runs tests, a reformatted docblock does not. What
remains goes through a chain of rules (see [How selection works](#how-selection-works)) that maps
changed files to the test files whose recorded edges include them. Anything unknown to the graph
(a new test) and any cached failure also always runs — a failure is never assumed fixed by itself.

**(c) Replay the rest — as a pass, not a skip.** Every test file *not* selected is never
re-executed; its last recorded result is served instead. Critically, phpunit-replay reports that
result as the **same status it actually had** — a pass with its real assertion count, a skip with
its real message — not as a synthetic "skipped, not run" placeholder. That distinction is the
reason for the name: results are *replayed*, not hidden.

| | Executed this run | Replayed by phpunit-replay | Skipped (typical file-level TIA) |
|---|---|---|---|
| Test body actually ran | yes | no | no |
| Counted in the summary totals | yes | yes | yes |
| Carries its real assertion count | yes (fresh) | yes (from the baseline) | **no — assertions are lost** |
| Triggers `--fail-on-skipped` | no | no | **yes, if your CI enables it** |
| Appears in JUnit as a complete test | yes | yes (`replayed="true"` property) | yes, but marked `<skipped/>` |

An annotated real summary line, printed below PHPUnit's own output:

```
Replay  ✓ 31 executed (31 affected, 0 uncached) · 4 replayed · 0 quarantined · baseline main@abc1234
```

| Segment | Meaning |
|---|---|
| `✓`/`✗` | overall PHPUnit result for this pass |
| `31 executed` | ran for real this pass = `affected + uncached + quarantined` |
| `31 affected` | selected by a rule (PhpEdge, TestFile, Sibling, Blade, Migration, Watch) |
| `0 uncached` | new to the graph, or forced to re-run (cached failure, a risky/incomplete result your config surfaces) |
| `4 replayed` | served from cache as their real recorded status, not run |
| `0 quarantined` | content key unchanged but result flipped (see [Keeping the cache honest](#keeping-the-cache-honest)) — always executed for real |
| `baseline main@abc1234` | git branch + commit the graph was recorded against |

Totals stay honest either way: `--fail-on-skipped` is never tripped by a replayed test (it isn't a
skip), and `--log-junit` output produced by phpunit-replay contains one complete `<testcase>` per
test, real or replayed.

## Install

```bash
composer require --dev manuglopez/phpunit-replay
```

Requirements:

| | |
|---|---|
| PHP | ^8.2 (PHPUnit 12 itself needs PHP >=8.3, PHPUnit 13 needs PHP >=8.4.1) |
| PHPUnit | ^11.5, ^12, or ^13 |
| Git | a repository with at least one commit — baselines and diffs are computed against git history |
| Coverage driver | `ext-pcov` **or** Xdebug with `xdebug.mode=coverage`, to record the dependency graph |
| `phpunit.xml`/`phpunit.xml.dist` | any valid PHPUnit configuration |
| Optional | `brianium/paratest` (`composer require --dev brianium/paratest`) for `--parallel`/`-p` |

No `php.ini` changes are needed for pcov: the wrapper enables it per invocation with
`-d pcov.enabled=1 -d pcov.directory=<project root>` (pcov instruments nothing without an explicit
`pcov.directory`, even though `phpinfo()` shows a cwd-derived default). Without pcov or Xdebug,
phpunit-replay disables itself with a warning and PHPUnit runs exactly as it would unpackaged.

## Quick start

```bash
$ vendor/bin/phpunit-replay status
root:      /home/you/project
branch:    main (default: main)
head:      0742ab4
state dir: ~/.phpunit-replay/project-a8138fc79c736026
driver:    pcov (loaded, enabled per run)
framework: plain

no baseline yet
```

```bash
$ vendor/bin/phpunit-replay record
............................S......                               35 / 35 (100%)
OK, but some tests were skipped!
Tests: 35, Assertions: 61, Skipped: 1.
Replay  ● recorded 35 tests in 7 test files · 12 source files · 18 edges · graph.json 6 KB · baseline main@0742ab4 · 0s
```

```bash
$ vendor/bin/phpunit-replay
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed · 0 quarantined · baseline main@0742ab4
```

Nothing changed, so nothing runs: the whole pass finishes in about 180 ms, PHP bootstrap included.
Now edit `src/Pricing.php` and ask what that would affect, without running anything:

```bash
$ vendor/bin/phpunit-replay --explain --dry-run
tests/CartTest.php                       ← PhpEdge  src/Pricing.php
tests/PricingTest.php                    ← PhpEdge  src/Pricing.php
Replay  2 test files would run (2 affected, 0 uncached, 0 quarantined), 33 tests would replay
```

```bash
$ vendor/bin/phpunit-replay
Replay  ✓ 6 executed (6 affected, 0 uncached) · 29 replayed · 0 quarantined · baseline main@0742ab4
```

Only the two test files with a recorded edge to `Pricing.php` ran; everything else replayed.

## Two ways to run it

### The wrapper (filtered mode) — the default, zero changes to your tests

`vendor/bin/phpunit-replay` resolves the affected test files (see
[How selection works](#how-selection-works)), then writes `.phpunit-replay.xml` next to your real
configuration: your `phpunit.xml`/`phpunit.xml.dist` verbatim, except `<testsuites>` is replaced by
a single suite listing one `<file>` per test file that must actually run (`<source>`, `<php>`,
`<extensions>`, bootstrap all kept as-is; `ReplayExtension` is injected as a bootstrap extension if
not already registered). PHPUnit then runs against that generated file with `--no-coverage` — the
raw pcov/Xdebug driver, not PHPUnit's own coverage, is what records edges — and the generated file
is deleted once the run finishes (`PHPUNIT_REPLAY_KEEP_RUN=1` keeps it for inspection).

Add `.phpunit-replay.xml` to your `.gitignore`.

Passing a PHPUnit selection option yourself — `--filter`, `--group`, `--exclude-group`,
`--testsuite`, an explicit path, `--covers`, `--uses` — disables the selection logic for that run:
PHPUnit runs exactly what you asked for, and only the results of the tests that ran are refreshed.

### The in-process trait — for when PHPUnit must see the whole suite

For an IDE that launches `phpunit` directly, `--coverage-html`, or simply not wanting the wrapper
in the loop. Extend `Manuglopez\Replay\PHPUnit\ReplayableTestCase` instead of
`PHPUnit\Framework\TestCase`, or add the trait to your own base class:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Manuglopez\Replay\PHPUnit\Replayable;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->isReplaying()) {
            return; // optional: skip expensive boot work too, not just the test body
        }

        // ...boot the app, RefreshDatabase, etc.
    }
}
```

and register the extension in `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension">
        <parameter name="mode" value="auto"/> <!-- auto|record|replay|off -->
    </bootstrap>
</extensions>
```

`setUp()` **always runs**, for every test, replayed or not — only the code guarded behind
`isReplaying()` (and only if you call it *after* `parent::setUp()`) is skipped; the trait hooks
the test method itself, never `setUp()`. On **PHPUnit 12 and 13** it overrides the
`invokeTestMethod()` hook cleanly; on **PHPUnit 11.5**, which has no such hook, a `#[Before]`
method swaps the test's private method name through reflection instead (see
`docs/spikes/in-process-replay.md` for the two mechanisms verified side by side).

Never replayed, in either mode: a `#[Depends]` provider for another test (a replayed provider
would hand its dependents a `null` return value), a cached failure or error (always reruns), a
test unknown to the graph (new), a test marked `#[NotCacheable]` or matched by `never_cache`, and a
quarantined test (see [Keeping the cache honest](#keeping-the-cache-honest)).

## Commands

`run` is the default, so `vendor/bin/phpunit-replay` and `vendor/bin/phpunit-replay run` are the
same thing. Anything after a literal `--`, or the first token phpunit-replay doesn't recognise, is
forwarded to `vendor/bin/phpunit` untouched.

| Command | Options | What it does |
|---|---|---|
| `run` (default) | `--fresh` `--no-remote` `--explain` `--dry-run` `--log-junit=FILE` `--allow-ci-baseline` `--parallel`/`-p[=N]` `[-- <phpunit args>]` | Runs only what's affected, replays the rest. See below for each option. |
| `record` | `--fresh` `--parallel`/`-p[=N]` | Runs the full suite unconditionally and records a fresh baseline. What CI runs on the default branch after a merge. |
| `verify` | `[-- <phpunit args>]` | Runs the full suite in record mode and compares every result against what a replay pass would have served — the divergence metric (see [Keeping the cache honest](#keeping-the-cache-honest)). |
| `status` | — | Prints the cached graph: root, branch, state dir, coverage driver, framework, file/edge/table counts, `graph.json` size, per-branch results, fingerprint drift, quarantine, not-cacheable count, remote, lifetime divergences. |
| `explain <path>` | — | Prints which recorded test files a change to `<path>` would affect, and by which rule — without running anything. |
| `prune` | `--flaky` `--branches` `--all` `--remote --keep-months=N` `--squash` | Drops stale state without touching a live pass. See below. |
| `push` | `--graph` | Publishes cached objects (and, with `--graph`, the branch baseline) to the configured remote. |
| `pull` | — | Fetches the branch baseline from the remote and stores it locally. |
| `baseline-path` | — | Prints the resolved state directory and nothing else — for CI to know what to archive. |

`run` options in detail:

| Option | Effect |
|---|---|
| `--fresh` | Ignore any cached baseline and record a fresh one. |
| `--no-remote` | Never contact a configured remote cache for this run. |
| `--explain` | Print which rule selected each test file, and why (same table as `explain <path>`). |
| `--dry-run` | Print what would run without running it; implies `--explain`. |
| `--log-junit=FILE` | Write a merged JUnit report to `FILE` — real results plus replayed ones, replayed entries marked `<property name="replayed" value="true"/>`. |
| `--allow-ci-baseline` | Let a run detected as CI (`CI` env var set) publish a branch baseline; without it, a CI run never updates the stored baseline. |
| `--parallel`/`-p[=N]` | Run through [Paratest](#parallel) instead of a single `phpunit` process. |

`prune` options: `--flaky` clears the quarantine; `--branches` removes baselines for branches git
no longer knows; `--all` deletes the whole state directory's contents; with no flag, prunes deleted
test files plus `--branches`. `--remote` switches to garbage-collecting the *remote* cache instead
of the local graph: it deletes object shards older than `--keep-months` (default 3) except objects
still referenced by a branch baseline, and `--squash` (git backend only) rewrites the remote
branch as a single orphan commit.

Environment variables — always win over `phpunit-replay.php`:

| Variable | Effect |
|---|---|
| `PHPUNIT_REPLAY=0` | Disables phpunit-replay entirely, even with the extension registered in `phpunit.xml`. |
| `PHPUNIT_REPLAY_DEBUG=1` | Prints every selection decision to stderr. |
| `PHPUNIT_REPLAY_STATE_DIR` | Overrides `state_dir`. |
| `PHPUNIT_REPLAY_REMOTE` / `PHPUNIT_REPLAY_REMOTE_TOKEN` | Override `remote` / `remote_token`. |
| `PHPUNIT_REPLAY_REMOTE_PUSH` | Overrides `remote_push` (`objects`\|`all`\|`off`). |
| `PHPUNIT_REPLAY_BASELINE_BRANCHES` | Comma-separated, overrides `baseline_branches`. |
| `PHPUNIT_REPLAY_DEFAULT_BRANCH` | Overrides `default_branch`. |
| `PHPUNIT_REPLAY_MODE` | Overrides the extension `mode` (also accepts the internal `record-subset`/`results-only` values the wrapper itself uses). |
| `PHPUNIT_REPLAY_KEEP_RUN=1` | Keeps the generated `.phpunit-replay.xml` and the run's partial directory for inspection. |
| `PHPUNIT_REPLAY_LEGACY_HOOK=1` | Forces in-process mode's PHPUnit 11.5 reflection fallback even on PHPUnit 12 or 13. |
| `CI` | Detected automatically; gates whether a `run` may publish a branch baseline (see `--allow-ci-baseline`). |

A few more `PHPUNIT_REPLAY_*` variables exist purely for internal wrapper-to-extension
communication (run id, resolved root/binary path); you shouldn't need to set them by hand.

## How selection works

**Changed files** are computed by diffing against the recorded baseline sha (it must be an
ancestor of `HEAD`, or a fresh recording is forced), unioned with the current working-tree status
(staged, unstaged, untracked — minus anything `git check-ignore` would exclude). Two filters then
narrow that set:

- **Content-hash filter** — a file is dropped if its normalized content hash is unchanged from the
  baseline commit: comment/whitespace-only edits to `.php` (tokenizer-based), Blade
  comments/whitespace, and JS/TS/Vue/Svelte comment/whitespace edits are all ignored this way.
- **Last-run snapshot** — a dirty file already accounted for in the previous run is dropped again
  (touching the same uncommitted change twice doesn't re-run its tests), but a reverted file is
  picked back up.

**Selection rules** run in order, each consuming what earlier rules didn't claim (the Laravel-only
ones are no-ops on a non-Laravel project — see [Laravel](#laravel)):

| # | Rule | Triggers on | Effect |
|---|---|---|---|
| 1 | `MigrationRule` (Laravel) | a changed `database/migrations/**/*.php` file | tables it creates/alters intersected against every test file's recorded tables |
| 2 | `PhpEdgeRule` | a changed (or deleted) file with an id in the graph | every test file whose recorded edges include it |
| 3 | `TestFileRule` | a changed file that is itself a test file (per `<testsuites>`) and still exists | affects itself |
| 4 | `SiblingRule` (Laravel) | a new/unknown `.php` file under a provider/listener/event/observer/policy/console-command/factory/seeder directory | tests with an edge to another file in the same directory |
| 5 | `BladeRule` (Laravel) | a changed `.blade.php` unknown to the graph | walked through static references (`@include`, `@extends`, `view()`, `<x-...>`) up to a Blade file the graph knows; tests with an edge to that ancestor |
| 6 | `WatchRule` | whatever is left, unknown to the graph | glob → test directory patterns: generic defaults (`.env*`, `phpunit.xml*`, `docker-compose*.y*ml`, fixtures/snapshots), framework defaults when detected (see below), and your own `watch` config |

On top of the rules, two more categories always run: **unknown test files** (on disk, matching
PHPUnit's test-path rules, but no recorded edges — new tests) and any cached result whose **status
must be re-run**: a failure or error always reruns; a risky/warning/notice/deprecation/
incomplete/skipped result reruns only if your PHPUnit configuration's `--fail-on-*` /
`displayDetailsOn*` settings would actually surface it.

Built-in `WatchRule` defaults by detected framework:

| Framework (detected by) | Patterns |
|---|---|
| Generic (always) | `.env*`, `phpunit.xml*`, `docker-compose*.y*ml`, `tests/**/Fixtures/**`, `tests/**/__snapshots__/**` |
| Laravel (`artisan` exists) | `config/**`, `routes/**`, `database/migrations/**`, `resources/views/**`, `lang/**`, `resources/lang/**`, `app/** !*.php`, `bootstrap/*.php` |
| Symfony (`config/bundles.php` exists) | `config/**`, `migrations/**`, `templates/**`, `translations/**` |

A **fingerprint** guards against incompatible baselines: its *structural* half (`composer.lock`,
`phpunit.xml(.dist)`, `phpunit-replay.php`, the cache schema version) changing discards the whole
graph and forces a fresh recording; its *environmental* half (PHP `MAJOR.MINOR`, coverage driver,
OS family) changing keeps the edges but discards cached results, which can't be trusted across a
PHP version or driver change.

Baselines are kept **per branch**. On a branch with no baseline of its own, phpunit-replay walks
an ordered list of candidates (`baseline_branches`, or the single `default_branch` as shorthand),
keeps only those whose recorded sha is an ancestor of `HEAD`, and picks whichever is **fewest files
different** from the current tree — the setup git-flow teams want: a feature branch cut from
`develop` inherits `develop`'s baseline, a hotfix cut from `main` inherits `main`'s, instead of
everything falling back to one shared default. `status` and `--explain` report which baseline was
chosen and why.

One rule is deliberate and worth internalizing: **a file no test ever executed affects nothing.**
A docs change, an unused helper, dead code — if no recorded edge points at it, it cannot change any
test's outcome, so nothing runs.

## Configuration

An optional `phpunit-replay.php` at the project root, returning an array (every key optional):

```php
<?php
// phpunit-replay.php
return [
    'state_dir' => null,                  // null = ~/.phpunit-replay/<project-key>
    'remote' => null,                     // null | 'file:///mnt/replay-cache' | 'https://cache.example.com/replay/' | 'git@github.com:org/project-replay-cache.git'
    'remote_token' => null,               // bearer token for the HTTP backend
    'remote_push' => 'objects',           // 'objects' (this machine's results only) | 'all' (also publish branch baselines — CI only) | 'off' (pull only)
    'remote_branch' => 'main',            // git backend: which branch of the cache repo to use
    'remote_refresh_seconds' => 300,      // git backend: how often the local mirror re-fetches
    'remote_timeout' => 60,               // git backend: total time budget for a push before giving up
    'default_branch' => null,             // null = autodetect (origin/HEAD, init.defaultBranch, main/master)
    'baseline_branches' => [],            // ordered nearest-baseline candidates for git-flow branching; [] = [default_branch]
    'watch' => [],                        // extra glob => test directory/file mappings, merged with the built-in defaults
    'never_cache' => [],                  // globs of test files that always run for real (see Keeping the cache honest)
    'quarantine_release_after' => 20,     // stable passes needed to leave automatic quarantine
    'laravel' => 'auto',                  // 'auto' | 'on' | 'off'
    'laravel_parallel_isolation' => true, // false to run --parallel on Laravel without per-worker database isolation
    'junit_merge' => true,                // merge cached results into --log-junit output
    'mode' => 'auto',                     // extension mode override; leave at 'auto' unless you know why not
    'hermeticity_heuristics' => false,    // reserved for a future heuristic (flagging suspicious tests in `status`); not implemented — leave false
];
```

Environment variables always win over this file — see the table in [Commands](#commands).

## Keeping the cache honest

Replaying a stale or wrong result would be worse than not caching at all, so phpunit-replay gives
you three ways to keep a test from ever being served stale, plus one that happens automatically:

1. **`#[NotCacheable(reason: '...')]`** on a test class or method — read by reflection while
   recording, persisted in the graph. The test always executes for real, even when its content key
   is unchanged (`use Manuglopez\Replay\Attributes\NotCacheable;`).
2. **`never_cache`** globs in `phpunit-replay.php` — any test file matching one always runs (e.g.
   `tests/Browser/**`, or tests that hit real external services).
3. **Automatic quarantine** — whenever new results are merged, a test whose content key is
   unchanged but whose result *class* flipped (pass↔fail, pass↔error) is recorded in `flaky.json`
   and forced to run every subsequent pass, until it's released via `prune --flaky` or
   automatically after `quarantine_release_after` (default 20) consecutive stable passes. A cached
   failure recovering to a pass is not a flip — that's the normal heal path.
4. **`verify`** is the objective metric for all of this: it runs the full suite in record mode and
   compares every result against what a normal replay pass would have served. A mismatch is a
   **divergence** — logged, quarantined automatically, and reflected in the summary:

   ```
   Verify  ✓ 1240 tests · 1198 would replay · 0 divergences (lifetime: 2 in 143 runs)
   ```

`status` shows the current quarantine list (with flip counts) and the lifetime divergence count —
the number to watch when deciding whether a fast `run` lane is trustworthy enough to become a PR
gate on its own (see [CI in two lanes](#ci-in-two-lanes)).

`hermeticity_heuristics` (off by default) is reserved for a future heuristic that would flag
suspicious-looking tests (unfaked `Carbon`/`Faker`, HTTP without `Http::fake()`) in `status` without
quarantining them — not implemented in this build; leave it `false`.

## Sharing the cache with your team

By default every machine — your laptop, a coworker's, each CI runner — keeps its own local
`graph.json`, so each re-records from scratch the first time it sees a given commit. Configuring a
**remote** turns that into a content-addressed object store any machine can push results to and
pull results from, so work one machine already did is inherited instead of repeated.

| | Local only (default) | Shared folder (`file://`) | HTTP (S3/MinIO, WebDAV) | Dedicated git repository | CI artifacts |
|---|---|---|---|---|---|
| Prerequisites | none | a mounted path all machines reach | an HTTP endpoint with GET/PUT/HEAD | an empty git repo + CI deploy key | none — built into GitHub Actions |
| Best for | solo projects, evaluating the package | one office/VPN | teams already on object storage | teams with git but no object storage | GitHub-only, zero extra infra |
| Failure behaviour | n/a | warning + local-only run | same | same | cache miss → full record for that job |

See **[docs/sharing-the-cache.md](docs/sharing-the-cache.md)** for setup steps for each backend,
`baseline_branches` for git-flow, and troubleshooting. A remote that's unreachable or misconfigured
always degrades to a warning on stderr and a local-only pass — it can never break a test run.

A brand-new checkout, once a baseline has been published:

```
$ vendor/bin/phpunit-replay
Replay  ✓ 0 executed (0 affected, 0 uncached) · 35 replayed (35 from remote) · 0 quarantined · baseline main@a1b2c3d
```

`(35 from remote)` means every one of those results came from the shared cache, not a local
recording — a machine that has never run this suite still gets a near-instant first pass.

`remote_push` governs who publishes what: developer machines and PR jobs default to `objects`
(only their own test-file results, keyed by content — safe to publish from anywhere, never
conflicts); only the CI job that owns the branch baseline (`remote_push: 'all'`, typically gated by
`--allow-ci-baseline`) publishes `graph/**`, which is what everyone else's cold start reads.

## CI in two lanes

The recommendation is the same one Pest gives for its own TIA: **PR CI keeps running the full,
unfiltered suite** as the actual merge gate — `phpunit-replay verify` does this while also
comparing every result against the cache, which is what keeps the baseline trustworthy and feeds
the divergence metric. A fast, optional lane runs `phpunit-replay run` for quick feedback in
minutes. A separate workflow records the baseline after each merge to the default branch
(`run --allow-ci-baseline` or `record --fresh`, then `push --graph`).

```
fast (every PR):   vendor/bin/phpunit-replay run     — quick feedback, not the gate
full (every PR):   vendor/bin/phpunit-replay verify  — the actual merge gate
baseline (on push to main/develop): record/run + push --graph
```

Requirements: a checkout with enough history that the baseline sha is an ancestor of `HEAD`
(`fetch-depth: 0` on GitHub Actions); pcov or Xdebug on the runner; a configured remote (or the
`baseline-path` + `actions/cache` alternative) so state carries between jobs. Working examples for
all three jobs, plus the monthly cache GC job, are in
**[.github/workflows/examples/](.github/workflows/examples/)**
(`ci.yml`, `tia-baseline.yml`, `tia-gc.yml`) — copy them into your own project's
`.github/workflows/`.

## Laravel

Autodetected: enabled when `<root>/artisan` exists and the `laravel` config key isn't `off`. The
package has no `illuminate/*` dependency itself — Laravel is reached through `class_exists()`,
string class names, and duck-typed calls.

What gets tracked while recording, once the app has booted for a test file:

- **Tables** — a query listener extracts the table name(s) touched by every
  `select|insert|update|delete|with|replace` query and links them to the test file (`migrations`,
  `sqlite_*`, `pg_*`, `information_schema*` excluded).
- **Blade views** — a view composer on `'*'` links every rendered view's path as a source
  dependency of the test file, exactly like a PHP file it directly touched.
- **Migration-aware tests** — every test file using `RefreshDatabase`, `DatabaseMigrations`, or
  `DatabaseTransactions` is additionally widened, when the graph is written, to cover every table
  any migration under `database/migrations/` creates — conservative by design.

The package's own `laravel-lite` fixture (4 Feature tests, 3 migrations, 2 Blade views)
demonstrates the effect end to end:

| Change | Result |
|---|---|
| Add a column to the `comments` migration | `3 executed (3 affected) · 1 replayed` — every test using `RefreshDatabase`; `HomePageTest`, which never touches the database, replays |
| Edit `welcome.blade.php` | `1 executed · 3 replayed` — only `HomePageTest`, the one test that renders it |

## Parallel

Add `--parallel`/`-p` to `run` or `record` to run the same filtered configuration through
[Paratest](https://github.com/paratestphp/paratest) instead of a single `phpunit` process:

```bash
phpunit-replay --parallel      # Paratest's own auto-detected process count
phpunit-replay -p 4            # 4 worker processes
phpunit-replay record -p 4     # a full parallel recording pass
```

Paratest is an optional `require-dev` dependency (`brianium/paratest`). When `--parallel`/`-p` is
given but `vendor/bin/paratest` isn't installed, phpunit-replay warns on stderr and falls back to a
sequential PHPUnit run rather than failing. Each worker writes its own partial results; they're
merged back together before updating the graph (edges by union, results last-write-wins), so the
summary line is the same regardless of process count. The coverage driver's ini flags travel to
Paratest's workers via `--passthru-php`.

On a Laravel project, `--parallel` also wires up Laravel's own per-worker database isolation
(`--runner=\Illuminate\Testing\ParallelRunner` plus `LARAVEL_PARALLEL_TESTING=1`) automatically,
whenever Laravel, Paratest, and a resolvable `Illuminate\Testing\ParallelRunner` are all present.
Without it, every worker migrates the same database instead of a per-worker one, which on a real
database engine surfaces as deadlocks or duplicate-key errors rather than a clean test failure — set
`laravel_parallel_isolation` to `false` if your project deliberately runs `--parallel` without
per-worker isolation. If the project's own Laravel application can't be resolved (no
`bootstrap/app.php` and no `Tests\CreatesApplication`), phpunit-replay warns and runs `--parallel`
without isolation instead of failing outright.

## Coverage reports with replay

Pass `--coverage-php=FILE` through to PHPUnit as usual. When phpunit-replay records a test file
with coverage active, it stores that file's own coverage slice (`<state dir>/coverage/<k>.cov`,
keyed by content). On a later pass, PHPUnit's own coverage — from whatever actually executed — is
merged with the stored snapshots of everything that replayed, so `--coverage-php` reflects the
*whole* suite, not just what ran:

```
Lines: 97.59% (81/83)          # 0 executed this pass — the figure came entirely from snapshots
```

**Limitation:** a snapshot only exists for a test file that was recorded *with* `--coverage-php`
active. A test that's risky, incomplete, skipped, or otherwise didn't produce a real coverage
sample at record time carries no piggyback coverage into a merged report — it simply contributes
nothing, the same as if it had never run. `--coverage-html`/`--coverage-clover` are produced by the
user from the merged `.php` report, same as any other PHPUnit coverage workflow.

## Comparison

Being specific about what each tool actually does, rather than what it aims to do:

| | [Pest 5 TIA](https://github.com/pestphp/pest) | [jasonmccreary/phpunit-tia](https://github.com/jasonmccreary/phpunit-tia) | [gosuperscript/phpunit-tia](https://github.com/gosuperscript/phpunit-tia) | phpunit-replay |
|---|---|---|---|---|
| Runner | Pest only (aborts on plain PHPUnit test classes) | PHPUnit | PHPUnit | PHPUnit 11.5+, 12, and 13, no Pest |
| Unaffected tests | Synthetic pass, real assertion count | **Skipped** | **Skipped** | Filtered mode: never loaded at all. In-process mode: synthetic pass with the real assertion count |
| Complete summary/JUnit | Yes | No — skipped tests lose their assertion count | No | Yes — cached results merge into the summary and, on request, into JUnit |
| Cosmetic-only changes ignored | Yes (tokenizer) | Partial | Partial | Yes (tokenizer-based content hash) |
| Per-branch baselines | Yes | No | No | Yes, plus nearest-baseline resolution for git-flow (`baseline_branches`) |
| Remote cache | GitHub Actions artifact via `gh` | No | No | Content-addressed, backend-agnostic: filesystem, HTTP/S3/MinIO, or a dedicated git repository |
| Non-hermetic test handling | No detection | No detection | No detection | `#[NotCacheable]`, `never_cache` globs, automatic quarantine on a pass/fail flip, `verify`'s divergence metric |
| Laravel awareness | Yes | No | No | Yes, optional, autodetected (tables, Blade, migration-aware tests) |
| Parallel | Yes, built in | No | No | Yes, via Paratest (`--parallel`/`-p`) |

The distinction that matters most: an unaffected test in either `phpunit-tia` package is reported
as **skipped** — its assertion count is gone, and depending on your PHPUnit configuration a skip
can even fail the build via `--fail-on-skipped`. In phpunit-replay it either never enters the run
at all (filtered mode) or is reported as a pass with the exact assertion count it produced last
time (in-process mode) — the summary reflects what really happened, not a gap papered over.

No code from `jasonmccreary/phpunit-tia` or `gosuperscript/phpunit-tia` was used. Roughly 60% of
Pest's own TIA engine — the framework-agnostic part — was ported by copy under its MIT license;
see [Attribution](#attribution).

## How other ecosystems do it, and where this sits

Test/task selection and caching by recorded dependency is not a new idea — most language and build
ecosystems have their own version of it:

| Ecosystem | Approach |
|---|---|
| [Go's `go test`](https://go.dev/doc/go1.10#test) | Result cache keyed by a hash of the test binary and its inputs; a cache hit prints `(cached)` instead of re-running |
| [Bazel](https://bazel.build/remote/caching) / [Buck2](https://buck2.build/) | Declared build/test graph plus a remote action cache keyed by action inputs |
| [Nx](https://nx.dev/concepts/how-caching-works) / [Turborepo](https://turbo.build/repo/docs/crafting-your-repository/caching) | Task input hashing with a shareable remote cache, at the JS/TS monorepo task level |
| [Jest `--onlyChanged`](https://jestjs.io/docs/cli#--onlychanged) / [Vitest](https://vitest.dev/guide/cli.html) | Static import-graph analysis from files git reports as changed |
| [pytest-testmon](https://github.com/tarpas/pytest-testmon) | Coverage-based selection, but at **line/block** granularity, not file granularity |
| [Ekstazi](http://ekstazi.org/) | Regression Test Selection for Java/Maven, via recorded class-level dependencies |
| [Datadog Intelligent Test Runner](https://docs.datadoghq.com/tests/intelligent_test_runner/) | Coverage-based, skips tests unaffected by the diff |
| [Gradle Predictive Test Selection](https://docs.gradle.com/enterprise/predictive-test-selection/) / [Launchable](https://www.launchableinc.com/) | ML-ranked test selection from historical failure data |

phpunit-replay sits closest to **testmon** and **Ekstazi**: coverage-based regression test
selection from a recorded dependency graph, not a static import guess. Its content-addressed
sharing is the same idea as Go's and Bazel's remote caches — a result keyed by what actually went
into producing it, reusable by any machine with the same inputs. Its replay-as-pass behavior is
the PHPUnit analogue of Go's `(cached)` marker: a result reported honestly as "this is what already
happened," not hidden. And its two-lane CI recommendation mirrors Gradle Predictive Test
Selection's own guidance — a fast advisory lane plus a full lane that remains the actual gate. The
one axis where phpunit-replay is intentionally coarser than testmon is granularity: **file-level**,
not block-level — see [Known limitations](#known-limitations).

## Known limitations

- **Edges are file-level, not method- or line-level.** Any change to a source file re-runs every
  test file whose recorded edges include it, even if the change touched an unrelated function.
- **In-process mode still runs `setUp()` for every test** — only the code guarded behind
  `isReplaying()`, called after `parent::setUp()`, is skipped; a replayed test still pays for
  anything outside that guard.
- **`#[Depends]` providers always execute for real**, in-process mode included: a replayed
  provider would hand its dependents a `null` return value, so such a test is never replayable.
- **Non-hermetic tests need an explicit marker.** phpunit-replay cannot detect on its own that a
  test's result depends on something outside its recorded source edges (the system clock, an
  external API); mark it `#[NotCacheable]` or a `never_cache` glob, or let automatic quarantine
  catch it after its first pass/fail flip.
- **The HTTP remote backend has no listing endpoint**, so `prune --remote` needs the filesystem or
  git backend to enumerate and garbage-collect object shards.
- **Coverage snapshots exist only for test files recorded with `--coverage-php` active** — a
  merged report has no data for a test file whose baseline was recorded without it.
- **A rebase invalidates a sha-based baseline** (the recorded commit is no longer an ancestor of
  `HEAD`, forcing a fresh recording), but content-addressed replay still works: a test file whose
  actual content is unchanged replays by its content key regardless of the rebase.

## Trying it on your project

1. From the real project (not this repository), to test a local checkout instead of a Packagist
   release: `composer config repositories.replay path ../phpunit-replay && composer require --dev
   manuglopez/phpunit-replay:@dev`.
2. `vendor/bin/phpunit-replay status` should say there is no baseline yet, and show the detected
   coverage driver, git root, default branch, and test framework.
3. `vendor/bin/phpunit-replay record` runs the whole suite once and ends with the recording
   summary: test files, source files, edges, `graph.json` size, and time taken.
4. Run `vendor/bin/phpunit-replay` again with nothing changed: 0 executed, everything replayed,
   finishing in under 2 seconds plus PHP's own bootstrap time.
5. Touch one class, then run `vendor/bin/phpunit-replay --explain`: it lists which test files are
   affected, and by which rule, without running anything.
6. `vendor/bin/phpunit-replay verify` runs the whole suite again in record mode and reports how
   many results diverge from what a normal replay pass would have served — `0 divergences` if
   nothing has drifted.
7. If something looks wrong: `--fresh` forces a clean recording, `status` shows what state is
   currently stored, and `PHPUNIT_REPLAY_DEBUG=1 vendor/bin/phpunit-replay` prints every selection
   decision to stderr.

## Attribution

Portions of this package are derived from [Pest](https://github.com/pestphp/pest)
(© Nuno Maduro, MIT license) — specifically the framework-agnostic parts of its Test Impact
Analysis engine. The full original license text is in
[`LICENSE-PEST.md`](LICENSE-PEST.md), and every ported file carries an `@see` docblock pointing at
its exact origin (file and commit). This project is not affiliated with, endorsed by, or
officially connected to Pest or its authors.

## License

MIT. See [`LICENSE`](LICENSE). © Manuel González.
