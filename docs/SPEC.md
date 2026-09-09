# manuglopez/phpunit-replay — Technical specification

> Test Impact Analysis + result replay for pure PHPUnit (11.5+ / 12 / 13).
> Document intended to be handed in full to Claude Code as an implementation prompt.
> Status: v0.1 — 2026-09-06.

---

## 0. One-paragraph summary

`phpunit-replay` records, per test file, which project source files run during its tests (raw coverage driver: pcov or Xdebug) and the result of each test (status, assertions, message, duration). On later runs it compares the working tree against the baseline (git + normalized content hash), computes which test files are affected, and **runs only those**, serving the rest from cache as *passed* with their real assertion count. It works in two modes: **filtered** (the `vendor/bin/phpunit-replay` wrapper generates a configuration that excludes unaffected files; zero changes to the tests) and **in-process** (a trait on the base `TestCase` replays inside the runner, for when PHPUnit must see every test). The cache is content-addressed and can be shared across machines.

Differences from what already exists (`jasonmccreary/phpunit-tia`, `gosuperscript/phpunit-tia`, Pest 5 TIA):

| | Pest 5 TIA | phpunit-tia (both) | **phpunit-replay** |
|---|---|---|---|
| Runner | Pest only (aborts with PHPUnit classes) | PHPUnit 12 / 13 | PHPUnit 11.5+, 12, and 13 |
| Unaffected tests | Synthetic pass with assertions | **Skipped** | Synthetic pass with assertions (in-process) or not loaded (filtered) |
| Full summary/JUnit | Yes | No | Yes: the wrapper merges cached results into the summary and the JUnit |
| Cosmetic changes ignored | Yes (tokenizer) | Partial | Yes (tokenizer) |
| Per-branch baselines | Yes | No | Yes |
| Remote cache | GitHub artifact via `gh` | No | Content-addressed: filesystem, HTTP/S3, any backend |
| Non-hermetic tests | No detection | No detection | `#[NotCacheable]`, globs, automatic quarantine on flip |
| Laravel (tables, Blade) | Yes | No | Yes (optional, auto-detected) |
| Parallel | Yes (internal Paratest) | No | Phase 2 (Paratest) |

---

## 1. Name, identity and layout

**Composer name:** `manuglopez/phpunit-replay`
**PHP namespace:** `Manuglopez\Replay`
**Binary:** `vendor/bin/phpunit-replay`
**Local state directory:** `~/.phpunit-replay/<project-key>/` (configurable; alternative `.phpunit-replay/` in the repo, gitignored)
**PHPUnit extension:** `Manuglopez\Replay\PHPUnit\ReplayExtension`
**Optional trait:** `Manuglopez\Replay\PHPUnit\Replayable`
**Attribute:** `Manuglopez\Replay\Attributes\NotCacheable`

Reason for the name: "replay" describes the differentiator (results are replayed, not skipped), it's searchable ("phpunit replay cache") and doesn't collide on Packagist. Alternative names considered and discarded: `phpunit-tia` (taken twice), `phpunit-impact` (generic), `dejavu` (nice but not searchable).

```
phpunit-replay/
├── bin/
│   └── phpunit-replay                 # CLI wrapper (Symfony Console)
├── src/
│   ├── Attributes/NotCacheable.php
│   ├── Cache/
│   │   ├── Graph.php                  # in-memory model + JSON encode/decode
│   │   ├── GraphStore.php             # atomic read/write of graph.json
│   │   ├── ContentHash.php            # xxh128 normalized per file type
│   │   ├── Fingerprint.php            # structural / environmental invalidation
│   │   ├── ProjectKey.php             # project key derived from the git remote
│   │   └── Remote/
│   │       ├── RemoteCache.php        # get/put/has interface
│   │       ├── NullRemoteCache.php
│   │       ├── FilesystemRemoteCache.php
│   │       └── HttpRemoteCache.php    # GET/PUT (S3 presigned, MinIO, nginx WebDAV…)
│   ├── Change/
│   │   ├── Git.php                    # git command wrappers (symfony/process)
│   │   ├── ChangedFiles.php           # diff + status + check-ignore + hash filter
│   │   └── LastRunTree.php            # second layer: dirty files already tested
│   ├── Record/
│   │   ├── CoverageDriver.php         # start/stop/collect interface
│   │   ├── PcovDriver.php
│   │   ├── XdebugDriver.php
│   │   ├── Recorder.php               # begin/end per test, reduces to files
│   │   ├── SourceScope.php            # what counts as a "source" file
│   │   └── ResultCollector.php        # status/assertions/time per test id
│   ├── Select/
│   │   ├── Selector.php               # affected() algorithm
│   │   ├── WatchPatterns.php          # globs → test directories
│   │   ├── TestPaths.php              # what a test file is (phpunit.xml)
│   │   └── Rules/                     # one class per rule (PHP edges, test file, watch, sibling, tables, blade)
│   ├── Hermeticity/
│   │   ├── Quarantine.php             # flaky.json: tests that flipped with no changes
│   │   └── Policy.php                 # NotCacheable + globs + quarantine → cacheable?
│   ├── PHPUnit/
│   │   ├── ReplayExtension.php        # PHPUnit\Runner\Extension\Extension
│   │   ├── Replayable.php             # trait: override runTest() + setUp guard
│   │   ├── ReplayState.php            # static singleton shared between trait and extension
│   │   ├── Subscribers/               # one per event
│   │   └── ConfigurationReader.php    # reads Registry::get(): source, testsuites, failOn*
│   ├── Report/
│   │   ├── Summary.php                # "N affected, M uncached, K replayed"
│   │   └── JUnitMerger.php            # merges real junit.xml + cached results
│   ├── Laravel/                       # only loaded if Illuminate\Container\Container exists
│   │   ├── TableTracker.php
│   │   ├── TableExtractor.php
│   │   ├── BladeTracker.php
│   │   └── MigrationTables.php
│   ├── Console/
│   │   ├── Application.php
│   │   └── Commands/{RunCommand,RecordCommand,StatusCommand,PruneCommand,BaselinePathCommand,ExplainCommand}.php
│   └── Config.php                     # loading of phpunit-replay.php / extension parameters / env
├── tests/
│   ├── Unit/                          # each component in isolation
│   ├── Integration/                   # minimal project fixtures run with real PHPUnit
│   └── Fixtures/Projects/{plain,laravel-lite}/
├── phpunit.xml.dist
├── composer.json
├── README.md
└── CHANGELOG.md
```

### composer.json (skeleton)

```json
{
  "name": "manuglopez/phpunit-replay",
  "description": "Test Impact Analysis and result replay for PHPUnit: run only what your changes affect, replay the rest from cache.",
  "type": "library",
  "license": "MIT",
  "keywords": ["phpunit", "testing", "test-impact-analysis", "cache", "tia", "speed"],
  "require": {
    "php": "^8.2",
    "ext-json": "*",
    "ext-tokenizer": "*",
    "phpunit/phpunit": "^11.5 || ^12.0 || ^13.0",
    "symfony/process": "^6.4 || ^7.0 || ^8.0",
    "symfony/console": "^6.4 || ^7.0 || ^8.0",
    "symfony/finder": "^6.4 || ^7.0 || ^8.0"
  },
  "suggest": {
    "ext-pcov": "Fastest coverage driver for recording the dependency graph",
    "ext-xdebug": "Alternative coverage driver (mode=coverage)",
    "brianium/paratest": "Parallel execution support"
  },
  "autoload": { "psr-4": { "Manuglopez\\Replay\\": "src/" } },
  "autoload-dev": { "psr-4": { "Manuglopez\\Replay\\Tests\\": "tests/" } },
  "bin": ["bin/phpunit-replay"],
  "config": { "sort-packages": true },
  "minimum-stability": "stable"
}
```

---

## 2. PHPUnit requirements and constraints that shape the design

These constraints are verified facts about the PHPUnit 10+ API and determine the architecture; don't argue with them, design around them:

1. **Extensions are read-only.** They receive events (`PHPUnit\Event\...`) and cannot skip tests, alter results, or modify the suite. Therefore "hard" selection can only happen **before** PHPUnit loads the tests (filtered mode) or **inside** the `TestCase` (in-process mode).
2. **`TestCase::runBare()` is `final`.** The full cycle cannot be intercepted. What *can* be overridden: `protected function runTest(): mixed` (invokes the test method via reflection) and `protected function setUp(): void`.
3. **The concrete class's `setUp()` wins over the base `TestCase` trait's.** If the user overrides `setUp()` in their test and calls `parent::setUp()`, our parent guard can return early, but whatever code the user puts *after* `parent::setUp()` will still run. That's why in-process mode can't guarantee the cost of `setUp()` is saved; it does guarantee the test body is saved and the result is a pass with the cached assertions. The big saving comes from filtered mode.
4. **pcov only instruments under `pcov.directory`.** The wrapper must launch PHP with `-d pcov.directory=<root>` and `-d pcov.enabled=1`. And PHPUnit's own coverage must be off (`--no-coverage`) while we record with the raw driver, or they clobber each other.
5. **Test ids** are `PHPUnit\Event\Code\TestMethod::id()` → `Fully\Qualified\Class::method` and, with data providers, `Class::method#datasetName` (or `#0`, `#1`). They are treated as opaque strings.
6. **Configuration available at runtime** via `PHPUnit\TextUI\Configuration\Registry::get()`: `source()->includeDirectories()/excludeDirectories()`, `testSuite()`, `failOnRisky()`, `failOnWarning()`, `failOnNotice()`, `failOnDeprecation()`, `failOnSkipped()`, `failOnIncomplete()`, `displayDetailsOn*()`, `testSuffixes()`.

---

## 3. Modes of operation

### 3.1 `filtered` mode (the wrapper's default, zero changes to tests)

```
vendor/bin/phpunit-replay [phpunit-replay options] [-- phpunit options]
```

1. Resolves repo root, branch, fingerprint; loads `graph.json` (local or remote).
2. If there's no valid graph → **record**: runs the full `phpunit` with the extension in recording mode and saves the graph. Done.
3. If there is a graph → computes `changed` and `affected` (§7).
4. Builds the list of test files to run: `affected ∪ unknown ∪ withCachedFailures ∪ notCacheable`.
5. If the list is empty: prints a "0 executed, N replayed" summary, optionally writes the merged JUnit, exits with 0.
6. Generates a temporary `phpunit.replay.xml`: a copy of the user's `phpunit.xml` with the `<testsuite>` entries replaced by a single testsuite listing a `<file>` for each test to run (keeps `<source>`, `<php>`, `<extensions>`, bootstrap, etc.). Injects `<extensions><bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/></extensions>` if not present.
7. Runs `php -d pcov.directory=<root> vendor/bin/phpunit -c phpunit.replay.xml --no-coverage [user args]` with the environment `PHPUNIT_REPLAY_MODE=record-subset`, `PHPUNIT_REPLAY_STATE_DIR=...`, `PHPUNIT_REPLAY_RUN_ID=...`. Stdout/stderr pass through to the user unchanged.
8. The extension records edges and results for the executed tests into `runs/<run-id>/{edges,results}.json`.
9. The wrapper merges into the graph, updates the branch baseline, snapshots the tree, prunes, writes `graph.json`, uploads to the remote if configured.
10. Prints its own summary line below PHPUnit's:
    `Replay: 38 executed (31 affected, 7 uncached), 1202 replayed from cache, 0 quarantined · saved ~4m12s`
11. If the user passed `--log-junit=X` (to phpunit-replay, not to phpunit), the wrapper generates a **complete** JUnit merging the real JUnit from the run with the cached results (`JUnitMerger`), marking cached ones with `<property name="replayed" value="true"/>`.
12. Exit code: PHPUnit's.

Partial PHPUnit options (`--filter`, `--group`, `--exclude-group`, `--testsuite`, an explicit path, `--covers`, `--uses`) **disable selection**: whatever the user asked for runs, with the extension in `results-only` mode (updates results, not edges or sha). `--random-order` with a different seed changes which test is the first in its process to load a given class, enum or const file, and therefore which test the file's load-time execution is attributed to — the top level of a file runs once per process, so only that first test sees it. Edges being file-level does not make this harmless on its own; what does is that a re-record **unions** edges instead of replacing them (SPEC §7.2), so a different order can only add an attribution, never take one away. `--order-by=random` with no seed is accepted as-is. This whole paragraph is specific to `run`; `record` (§3.3) refuses a partial selection instead of degrading to `results-only`, since a partial run cannot produce record's one product, a complete baseline.

### 3.2 `in-process` mode (the `Replayable` trait)

For when PHPUnit must walk the whole suite (an IDE that launches `phpunit` directly, `--coverage`, or simply not wanting the wrapper). The user adds the trait to their base `TestCase` and registers the extension in `phpunit.xml`:

```php
abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use \Manuglopez\Replay\PHPUnit\Replayable;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->isReplaying()) { return; }   // optional: saves the expensive boot
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

Trait behavior (§6.3): when `runTest()` is called, it asks `ReplayState`; if the test is replayable, it does `addToAssertionCount($cached)` (and `expectNotToPerformAssertions()` if there were 0) and returns `null` without invoking the method. Cached skipped/incomplete are replayed with `markTestSkipped/markTestIncomplete($message)`. Failures are **never** replayed.

This mode also allows `phpunit --coverage-html` with TIA: the extension cannot merge serialized coverage the way Pest's first version does; phase 3 implements `CoverageMerger` processing `--coverage-php`.

### 3.3 Explicit `record` mode

`vendor/bin/phpunit-replay record [--fresh]` → full suite with recording. This is what runs in CI after a merge to `main` to publish the baseline.

`record` runs the full suite unconditionally: it never takes a selection. A PHPUnit
selection forwarded after `--` (`--filter`, `--exclude-filter`, `--group`, `--exclude-group`,
`--testsuite`, `--exclude-testsuite`, an explicit path) is refused rather than silently
honoured in `results-only` mode the way §3.1 describes for `run` — a partial run cannot
produce the one thing `record` exists to produce: a complete, prunable, publishable
baseline. The wrapper degrades instead (as it does for every other reason it cannot proceed
as asked): the user's selection still runs, for real, via plain, unwrapped PHPUnit, so
`record` never silently does nothing observable; the graph is left completely untouched;
and — like every other degraded `record` — the exit code is `2`, not a false `0`, whenever
that fallback run itself passes, precisely because nothing was published.

---

## 4. State and cache format

### 4.1 Directory

```
~/.phpunit-replay/<slug>-<hash16>/
├── graph.json
├── flaky.json              # quarantine (§8)
├── last-run.json           # {branch, sha, tree:{rel:hash}, finishedAt}
├── runs/<run-id>/          # partials from a run in progress; deleted after merging
│   ├── edges.json
│   ├── results.json
│   └── meta.json           # {driver, phpVersion, truncated, startedAt}
└── remote/                 # local cache of downloaded remote objects
```

`ProjectKey`: `slug(basename(root)) . '-' . substr(sha256(normalizedOriginUrl ?? realpath(root)), 0, 16)`. URL normalization: strip protocol, user, `.git`, lowercase → `github.com/manuglopez/phpunit-replay`. This way clones and worktrees share state.

### 4.2 graph.json (schema 1)

```json
{
  "schema": 1,
  "generator": "manuglopez/phpunit-replay 0.1.0",
  "fingerprint": {
    "structural": {
      "schema": 1,
      "composer_lock": "xxh128…",
      "phpunit_xml": "xxh128…|null",
      "phpunit_xml_dist": "xxh128…|null",
      "replay_config": "xxh128…|null"
    },
    "environmental": { "php": "8.3", "driver": "pcov", "os": "Darwin" }
  },
  "files": ["app/Models/Ad.php", "app/Services/Pricing.php", "tests/Feature/AdTest.php"],
  "edges": { "tests/Feature/AdTest.php": [0, 1, 2] },
  "test_tables": { "tests/Feature/AdTest.php": ["ads", "users"] },
  "not_cacheable": ["tests/Feature/ExternalApiTest.php"],
  "baselines": {
    "main": {
      "sha": "40-hex",
      "complete": true,
      "results": {
        "Tests\\Feature\\AdTest::test_publishes": {"s": 0, "a": 3, "t": 0.041, "m": "", "f": "tests/Feature/AdTest.php", "k": "xxh128…"},
        "Tests\\Feature\\AdTest::test_prices#premium": {"s": 0, "a": 1, "t": 0.012, "m": "", "f": "tests/Feature/AdTest.php", "k": "xxh128…"}
      }
    }
  }
}
```

Rules:

- `files` is a file table with integer ids; `edges` is **test file → ids** (the only direction stored; the reverse is built in memory on load).
- A test file present in `edges` with an empty list is "known with no dependencies" (different from unknown). `knowsTest(rel)`.
- `s` = `PHPUnit\Framework\TestStatus\TestStatus::asInt()`: 0 success, 1 skipped, 2 incomplete, 3 notice, 4 deprecation, 5 risky, 6 warning, 7 failure, 8 error. `a` assertions, `t` seconds, `m` message, `f` relative file, `k` **content key** (§4.3).
- Per-branch baselines. Reading on branch `X`: `array_replace(results[default], results[X])` unless `X.complete === true`, in which case the default's results are only used for files not covered by `X`.
- Defensive `decode()`: a mismatched `schema` → the graph is discarded with a warning; malformed sections → those entries are ignored.
- Atomic write: `tmp` + `rename`.

### 4.3 Content key per test file (content-addressed)

```
k = xxh128(
  fingerprint.structural (canonical json) .
  ContentHash(testFile) .
  join(sorted(map(deps, f => rel(f) . ':' . ContentHash(f))))
)
```

`k` is computed while recording and saved with each result. It serves two purposes: (a) the remote cache is indexed by `k` (`objects/<k>.json` holding the results of every test in that file), so any machine with the same contents gets the same results without needing the same `sha`; (b) quarantine detects flips: same `k`, different `s` → not hermetic.

#### 4.3.1 `static_declaration_edges` (opt-in, default off)

`deps` above comes from coverage attribution, and PHP executes a file's top level **exactly
once per process**. The load-time footprint of a declaration-only file — an enum's cases, a
constants class, an interface, a `return [...]` config or language file — is therefore
credited to whichever test in that process loaded it first, and every other test that depends
on it gets no edge at all. Union-on-re-record (§7.3) can never invent the missing edge,
because it never existed.

With `static_declaration_edges => true` (`phpunit-replay.php`, or the internal
`PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES=1` the wrapper passes to the PHPUnit child), `deps`
is built from two order-independent sources instead:

1. **Behavioural edges.** A coverage hit counts only when the executed line falls inside a
   function/method/closure statement list (`Analysis\FileFacts`, nikic/php-parser, cached by
   `ContentHash` under `<stateDir>/analysis/`). Those lines run when something *calls* them,
   so the attribution is identical in every process and every Paratest distribution. A file
   php-parser cannot read keeps today's Pest heuristic — "cannot classify" must never turn
   into "no dependencies".
2. **Static edges, one hop.** Any file that declares a class-like name — with or without
   method bodies — becomes a dependency of a test when the test's **own source** names
   something it declares; and, when the file has **no function body at all**, also when any
   file that is already a behavioural dependency of the test names it (`Analysis\StaticEdges`,
   resolved names plus class-shaped string literals). No second hop, and hop sources are the
   dependencies *this run's coverage* reported, never the graph's accumulated list — following
   the graph would reach one file further on every re-record and make the graph a function of
   how many partial passes had run rather than of the source tree.

   The two halves partition by **kind of signal**, not by file shape: coverage owns "a test
   executed this code", names own "a test mentions this symbol". An earlier design split them
   by shape — static edges only for files with no body — and the two then failed to meet in
   the middle: a file with even one method body whose bodies a given test never entered got no
   behavioural edge and no static edge, while the residue net below also declined it because
   another test's coverage had already given it a `fileId`. Adding one method to an enum was
   enough to drop it out of the graph for every test that merely read its cases.

   The asymmetry in the rule is what makes it affordable, and it is measured. A file with no
   body can never be attributed by coverage at all, so the transitive hop is the only mechanism
   it will ever have, and its reach is narrow: on a 2,195-file Laravel project with 726 tests
   and 62,743 recorded edges, 730 added edges. A file that *has* bodies is already reachable by
   coverage from every test that calls into it, so the only edge it can lack is the one from a
   test that names it without calling it — and letting a behavioural dependency reach it too
   costs 66,219 edges on the same project (+105.5%, median dependencies per test 82 → 173),
   60% of them from five files that name classes they merely *register* (`routes/web.php`,
   `routes/api.php`, `routes/breadcrumbs.php`, `routes/console.php` and one kitchen-sink
   model), each a behavioural dependency of 700 of the 726 tests. Every test that hit any route
   would inherit an edge to every controller in the application, with no safety gained. With
   the asymmetry the same project gains **1,781 edges, +2.84%**, median dependencies per test
   82 → 84, and those five files contribute exactly zero.

   That figure is the *additive* half of the flag, and is easy to misread as its total cost.
   The body filter's removal is the larger half by a factor of twenty: measured on the same
   project, turning the flag on takes the graph from 61,278 edges to 21,706 — **−64.6%**,
   removing 41,488 coverage edges while adding 1,916. See
   [reproducibility.md](reproducibility.md) for that measurement and for what the removed
   edges were.

Everything neither source reaches — `lang/` and `config/` files and Blade templates (they
declare no name), a file php-parser cannot read (nothing about it is known, so its edges fall
back to the Pest heuristic), a brand-new `.php` file — is **residue**, and residue is covered
conservatively rather than dropped: `Select\RunListBuilder` registers the changed path itself
as a watch pattern onto every test directory *and* every `<testsuite><file>` entry (§7.2.6),
so `WatchRule` selects everything the graph knows. Worth stating plainly what that costs in
practice, because it is the flag's second and less obvious price: with the flag on, `config/`
and `lang/` files leave the graph's `files` table entirely (55 `config/*.php`,
`bootstrap/app.php` and 4 `lang/*.php` on the measured project), so **editing any one of them
re-runs the whole suite** — 9,056 tests where the flag off would have run the ~700 those files
held edges to. The pattern key is the changed path
verbatim, so `WatchPatterns` compares a key to the path for equality before parsing it as a
glob — otherwise a path containing whitespace, or starting with `!`, would never match its own
file. That net is deliberately wider than necessary and deliberately not
`Fingerprint::structuralDrift`, which would discard every graph on every machine over a
translation tweak.

The **invariant** the flag is held to: with it on, no test loses an edge the flag-off pass gave
it, unless that edge was itself first-loader noise *and* something else now covers the file —
either the tests that call into it or name it (it has a `fileId`, so §7.2.2 selects them), or
the whole suite (it has none, so the residue net selects everything). A changed file is never
silently attributed to nobody. `Select\Rules\SiblingRule` therefore only consumes a path once
it has actually found a tested sibling to stand on, the way `BladeRule` does with its
ancestors: consuming it unmatched hid it from `WatchRule`, which with the flag on is the only
thing that would have covered it (the Laravel watch default for `app/` is `app/** !*.php` and
excludes exactly those files, so with the flag off the guard changes no outcome).

The classifier's cache lives at `<stateDir>/analysis/v<rules>/<xx>/<hash>.json`, one
immutable entry per distinct file content (so Paratest workers can write it concurrently and
a lost write costs one re-parse, never a wrong answer). `<hash>` is `xxh128` of the file's
**raw bytes**, deliberately not `ContentHash` — the payload is a list of line ranges, so the
normalisation that makes a comment-only edit invisible to the content key would serve line
numbers that no longer describe the file. The bytes are read once and both the key and the
facts come from that one read, and a failed scan is never stored (a swallowed EMFILE would
otherwise pin "unparseable" to that content forever). The `v<rules>` segment is
`DeclarationScanner::RULES_VERSION`, and it exists because a content hash answers "has this
file changed" and never "have we changed our mind about what this file means" — bump it
whenever the classification rules move, or every machine keeps serving the old verdict for
unchanged files. A bump is also structural drift (§4.5), because re-parsing alone corrects
the facts and leaves every already-recorded edge as the old rules got it: `unionEdges()` only
grows. `prune --all` clears the whole thing along with the rest of the state directory.

The flag participates in the **structural** fingerprint (§4.5), and only when it is on: a
graph whose edges came from coverage attribution and a graph that also carries static edges
are not comparable, so flipping it in either direction is structural drift and forces a fresh
record. Adding the key unconditionally would have invalidated every existing cache
everywhere; leaving it out when off is what makes the feature shippable.

**Once-per-process residue.** A body running is not always the declaration/first-loader shape
above — a Laravel migration, seeder or console command (`app/Console/Commands/`) has real method
bodies, so neither half above skips it, but that body executes **once per worker process**,
guarded by the framework's own testing lifecycle (`RefreshDatabase`/`DatabaseMigrations` migrate
and seed a worker's database once, not once per test): the same first-loader shape as a
declaration, one level down the call stack. Coverage still credits it to whichever test happened
to trigger it first in that worker, and the holder set measured on the project above **shrinks
rather than swaps** across re-recordings (see [reproducibility.md](reproducibility.md)
"Once-per-process residue" for the mechanism and the numbers) — `Graph::unionEdges()` cannot
repair that the way it repairs an ordinary partial re-record, because a test that never once
happened to be the first loader in any worker distribution never gets the edge to lose. With the
flag on, `Cache\GraphUpdater::apply()` refuses to record a **coverage-derived** edge to a file
under `database/migrations/`, `database/seeders/` or `app/Console/Commands/`
(`Laravel\OncePerProcessPaths`, autodetected the same way as the rest of the Laravel integration,
§10) — the file keeps no `fileId`, which is exactly the residue bucket two paragraphs up, so a
change to it re-runs everything the graph knows rather than only whichever test happened to be
credited. The **static** hop (above) is deliberately untouched: a test whose own source names one
of these files still gets the edge from it, because a name reference is order-independent
evidence and coverage attribution is the only half of the two that is not — only
`Graph::unionEdges()`'s coverage-derived write is refused, never `Analysis\StaticEdges::expand()`'s.
Gated on `static_declaration_edges` exactly like everything else on this page: with the flag off
there is no residue net to catch a refused edge (`Select\RunListBuilder::build()` only installs
`Select\ResiduePatterns` when the flag is on), so the coverage-derived edge is recorded exactly as
it always was — refusing it with no net would be a strictly worse false green than the one this
closes.

This closes the gap for the three conventions the measured suite actually moved on. Two shapes in
that same measurement are **not** covered and keep moving: an `app/Services/*` class and a
factory/model pair. Nothing about their path says "executes once per process" the way a
migration's does — a service might be a request-scoped singleton or might not be, and guessing
wrong in the "still gets an edge" direction reintroduces the bug. Nor is a live Illuminate
container ever consulted to find out (e.g. asking the booted application for its configured
migration paths): `Cache\GraphUpdater::apply()` runs both from the wrapper, with nothing loaded,
and in-process, with Laravel already booted (§6.1) — an answer that depends on which of those
handled a given recording pass would be the same process-shape-dependent non-determinism this fix
removes, one level up. A project with an unconventional migration layout
(`Modules/*/Database/Migrations`) is therefore unknown to `OncePerProcessPaths` for the same
reason, and keeps today's behaviour. No configuration governs any of this, deliberately, matching
§7.3's own precedent: extending or narrowing the convention list is a code change, not a project
setting.

### 4.4 ContentHash (normalization)

- `.php` (not `.blade.php`): `token_get_all`, drop `T_WHITESPACE`, `T_COMMENT`, `T_DOC_COMMENT`, concatenate the `text` of each token, `hash('xxh128', ...)`. If the tokenizer returns empty → hash the raw content.
- `.blade.php`: strip `{{-- ... --}}` (multiline), collapse `\s+` to a single space, trim.
- `.js .mjs .cjs .ts .mts .tsx .jsx .vue .svelte`: strip lines that are only `// ...` and `/* */` blocks that occupy whole lines, collapse spaces, trim.
- everything else: raw hash.

`ContentHash::of(path): ?string` (null if it doesn't exist) and `ContentHash::ofContent(string $content, string $pathForType): string`.

### 4.5 Fingerprint

- **Structural** (change → graph fully discarded, fresh record): `composer.lock`, `phpunit.xml`, `phpunit.xml.dist`, `phpunit-replay.php`, and the package's `SCHEMA_VERSION` constant. Only hashed if tracked by git. Plus `static_declaration_edges: true` and `analysis_rules: DeclarationScanner::RULES_VERSION`, both present only while that flag is on (§4.3.1) — a rules bump has to force a fresh record, since the graph's edges are never re-derived otherwise. Plus `edges_exclude_ignored: true`, present **unconditionally** (not gated on any flag): it names, in a drift report, the one-time fresh record every existing graph needs once an edge can no longer point at a file `git` ignores (§7.3) — a bare `SCHEMA_VERSION` bump would force the same fresh record but could never be *named*, since `structuralDrift()` always skips the `schema` key.
- **Environmental** (change → results discarded, edges kept): PHP `MAJOR.MINOR` version, driver, `PHP_OS_FAMILY`.
- Checked at the start **and at the end** of the run: if it changed during execution, the edges recorded in that run are discarded.

---

## 5. Recording

### 5.1 Drivers

```php
interface CoverageDriver {
    public static function available(): bool;
    public function start(): void;
    /** @return array<string, array<int,int>> file => [line => hits] */
    public function stop(): array;
}
```

- `PcovDriver::available()`: `function_exists('pcov\start') && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL)`. `start()`: `\pcov\clear(); \pcov\start();`. `stop()`: `\pcov\stop(); $files = array_filter(\pcov\waiting(), $scope->contains(...)); return \pcov\collect(\pcov\inclusive, $files);`.
- `XdebugDriver::available()`: `function_exists('xdebug_start_code_coverage') && in_array('coverage', xdebug_info('mode'), true)`. `start()`: `xdebug_start_code_coverage()` (no flags: hits only). `stop()`: `$d = xdebug_get_code_coverage(); xdebug_stop_code_coverage(true); return filtered by scope`.
- If the wrapper detects pcov with a `pcov.directory` different from the root, it relaunches PHP with `-d pcov.directory=<root>` (env var `PHPUNIT_REPLAY_RESTARTED=1` avoids loops).

### 5.2 SourceScope

Includes: the directories from `<source><include>` in `phpunit.xml` **plus** every top-level directory of the project except `vendor, node_modules, .git, .idea, .vscode, .github, .phpunit.cache, .cache, storage/framework, storage/logs, bootstrap/cache` and the `<source><exclude>` entries. If there are no includes, the entire root. `tests/` **is** in scope (a test file is a source node of itself; this way "the test changed" is resolved via edges). `vendor/` is always excluded when relativizing.

### 5.3 Recorder

Per test: `beginTest(testFile)` on `Test\PreparationStarted` (to capture `setUp`), `endTest()` on `Test\Finished` (after `tearDown`). It's reduced to file level with this heuristic, copied from Pest because it works: a file counts if it has any line with hits > 0 **unless** the driver reports unexecuted lines and the only executed one is the highest-numbered one (a file that was merely autoloaded). `perTestFiles[testFile][sourceFile] = true`.

Edges are per **test file**, not per method. Test file = `TestMethod::file()`; fallback `(new ReflectionClass($className))->getFileName()`.

### 5.4 ResultCollector (PHPUnit subscribers)

| Event | Action |
|---|---|
| `Test\PreparationStarted` | `start(id, file)`; `hrtime` |
| `Test\Passed` | `status = success` unless an issue is already recorded for this id (then it only refreshes the time) |
| `Test\Failed` / `Errored` | `status = failure/error` with `throwable()->message()`; overwrites any issue |
| `Test\Skipped` / `MarkedIncomplete` | `status = skipped/incomplete` with message |
| `Test\ConsideredRisky` | `status = risky` if nothing worse is recorded |
| `Test\{Warning,PhpWarning,Notice,PhpNotice,Deprecation,PhpDeprecation}Triggered` | if `!$event->wasSuppressed()`, `recordIssue(status)` — only escalates, never downgrades |
| `Test\Finished` | `assertions = $event->numberOfAssertionsPerformed()`, `time`, closes |
| `TestRunner\ExecutionFinished` | `flush()` → `runs/<run-id>/results.json`, `edges.json`, `meta.json` |
| `TestRunner\ExecutionAborted`, `Bail` | `meta.truncated = true` |

Status precedence: `error > failure > warning > risky > deprecation > notice > incomplete > skipped > success`.

---

## 6. Extension, shared state and trait

### 6.1 ReplayExtension

```php
final class ReplayExtension implements \PHPUnit\Runner\Extension\Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $config = Config::fromExtensionParameters($parameters)->mergeEnv($_SERVER);
        $state  = ReplayState::boot($config, $configuration);      // decides mode: record | replay | results-only | off
        if ($state->mode() === Mode::Off) return;

        $facade->registerSubscribers(...ResultCollector::subscribers($state));
        if ($state->recordsEdges()) {
            $facade->registerSubscribers(new StartRecordingOnPreparationStarted($state), new StopRecordingOnFinished($state));
        }
        $facade->registerSubscriber(new FlushOnExecutionFinished($state));
    }
}
```

Mode decision inside the extension (when the wrapper isn't orchestrating it):

- The `PHPUNIT_REPLAY_MODE` env var (set by the wrapper) wins.
- Without the env var, with parameter `mode=auto`: if there's a valid graph and the fingerprint matches → `replay` (recording edges for whatever runs, if a driver is present); if there's no graph and a driver is available → `record`; without a driver → `off` with a warning.
- If partial selection is detected in `$configuration` (`hasFilter()`, `hasGroups()`, `hasExcludeGroups()`, `includeTestSuite()`, `cliArguments()` with a path) → `results-only`. When `mode=record` specifically (a standing "always record" configuration, unlike `auto` landing on `record` only for lack of a baseline yet) is what the selection defeated, the summary line notes it (`record mode: baseline NOT refreshed (partial selection)`) instead of staying silent — the same guarantee record's own CLI command (§3.3) enforces by refusing outright, applied here as visibility instead, since a standing config is not a one-shot command and downgrading to `results-only` is still the correct behaviour for an ordinary filtered run.

### 6.2 ReplayState

Static singleton (necessary because the trait on the `TestCase` has no dependency injection). Exposes:

```php
ReplayState::decide(string $testFile, string $testId): Decision  // Run | ReplayPass(assertions) | ReplaySkipped(msg) | ReplayIncomplete(msg)
ReplayState::counters(): array{affected:int, uncached:int, replayed:int, quarantined:int}
```

`decide()` in replay mode:

```
rel = relative(realpath(testFile))
if rel ∈ affected            → Run  (affected++)
if !graph.knowsTest(rel)     → Run  (uncached++)
if policy.notCacheable(rel, testId) → Run (quarantined++)
r = graph.result(branch, testId)
if r === null                → Run  (uncached++)         // new test in a known file
if shouldRerun(r.status)     → Run
replayed++ ; return Replay*(r)
```

`shouldRerun(status)`: failure/error/unknown → always; risky → `failOnRisky()`; warning → `failOnWarning() || displayDetailsOnTestsThatTriggerWarnings()`; notice/deprecation → analogous; incomplete → `failOnIncomplete() || displayDetailsOnIncompleteTests()`; skipped → `failOnSkipped() || displayDetailsOnSkippedTests()`.

### 6.3 Replayable trait

```php
trait Replayable
{
    private ?Decision $__replayDecision = null;

    protected function isReplaying(): bool
    {
        return $this->__replayDecision()->isReplay();
    }

    private function __replayDecision(): Decision
    {
        return $this->__replayDecision ??= ReplayState::decide(
            (new \ReflectionClass(static::class))->getFileName(),
            $this->valueObjectForEvents()->id(),
        );
    }

    protected function runTest(): mixed
    {
        $decision = $this->__replayDecision();

        return match (true) {
            $decision instanceof ReplayPass => $this->__replayPass($decision),
            $decision instanceof ReplaySkipped => $this->markTestSkipped($decision->message),
            $decision instanceof ReplayIncomplete => $this->markTestIncomplete($decision->message),
            default => parent::runTest(),
        };
    }

    private function __replayPass(ReplayPass $d): null
    {
        if ($d->assertions === 0) {
            $this->expectNotToPerformAssertions();
        }
        $this->addToAssertionCount($d->assertions);
        ReplayState::markReplayed($this->valueObjectForEvents()->id(), $d);
        return null;
    }
}
```

Notes: `valueObjectForEvents()` has been public on `TestCase` since PHPUnit 10 and returns a `TestMethod` with `id()`. A test replayed as *risky* (0 assertions, without `expectNotToPerformAssertions`) naturally comes out risky again; that's why `ReplayPass` with `wasRisky=true` doesn't call `expectNotToPerformAssertions()`. When persisted, the result of a replayed test keeps its **original** status/time/assertions, not the synthetic run's.

Documented limitation: `#[Depends]` on a replayed test receives `null`. The default policy is to **never replay tests that are another test's dependency** (detected via `MetadataRegistry::parser()->forMethod()` looking for `Depends*`) — minimal cost, eliminates the problem.

---

## 7. Change detection and selection

### 7.1 ChangedFiles::since(sha)

1. `git merge-base --is-ancestor <sha> HEAD`; if it fails → the baseline is unreachable → fresh record (with a warning).
2. `git diff --name-only --no-renames <sha>..HEAD` (renames show up as delete+add).
3. `git status --porcelain=v1 -z --untracked-files=all`.
4. Union → `git check-ignore --no-index -z --stdin` to drop ignored files.
5. Content filter: for each candidate, `ContentHash::of(workingTree) === ContentHash::ofContent(git show <sha>:<path>)` → dropped. Deletions and new files remain.
6. `LastRunTree`: candidates ∪ keys from `last-run.tree`; kept only if the current hash differs from the snapshot (or the file disappeared). A dirty file already tested isn't tested again; a reverted one is re-run.

### 7.2 Selector::affected(changed): set<testFile>

Chain of rules, each receiving the changes the previous ones didn't consume:

1. **MigrationRule** (if `test_tables` exists): a changed `database/migrations/**/*.php` file → `TableExtractor::fromMigrationSource()` (`Schema::create|table|drop|dropIfExists|rename`, `CREATE|ALTER|DROP TABLE`, `DB::table('x')`) → tests whose tables intersect. An unparseable migration falls back to WatchRule.
2. **PhpEdgeRule**: a changed file with an id in `files` → every test file whose edges contain it. A **deleted** file with an id → the same (the edges still stand).
3. **TestFileRule**: a changed file that `TestPaths::isTestFile()` recognizes (directories and suffixes from `<testsuites>`) and exists on disk → affected.
4. **SiblingRule** (Laravel, optional): a new/unknown `.php` file under `app/Providers/, app/Listeners/, app/Events/, app/Observers/, app/Policies/, app/Console/Commands/, database/factories/, database/seeders/` → tests with edges to some file in the same directory.
5. **BladeRule** (Laravel, optional): a changed `.blade.php` not in the graph → computes static ancestors (`@include`, `@extends`, `@component`, `view('x')`, `<x-name`) and affects tests with edges to any ancestor.
6. **WatchRule**: whatever is left and unknown to the graph → `WatchPatterns` (globs → test directories). Defaults:
   - Generic: `.env*`, `phpunit.xml*`, `docker-compose*.y*ml`, `tests/**/Fixtures/**`, `tests/**/__snapshots__/**` → `tests`.
   - Laravel (if `artisan` exists): `config/**`, `routes/**`, `database/migrations/**`, `resources/views/**`, `lang/**`, `resources/lang/**`, `app/** !*.php`, `bootstrap/*.php` → `tests`.
   - Symfony (if `config/bundles.php` exists): `config/**`, `migrations/**`, `templates/**`, `translations/**` → `tests`.
   - User: `phpunit-replay.php` → `'watch' => ['config/billing/**' => 'tests/Feature/Billing']`. Merged with the defaults.
7. Files that match nothing (README, docs) → affect nothing. This is deliberate and must be documented: if a `.php` file under `app/` isn't in any edge, it's because no test executed it.

Post-processing: if any source `.php` file changed and **no driver is available** → run the full suite (edges can't be refreshed). Test files that no longer exist on disk are dropped.

### 7.3 Writing after the run

- Full run (record or replay without truncation): `setRecordedSha(branch, HEAD)`, `unionEdges` for the test files executed (merged into whatever the graph already had per file, never replaced — a coverage driver only credits a file's declaration footprint to whichever test loaded it first in that process, so a partial re-record must not let that attribution silently drop a real dependency; an edge whose file still exists is only ever shed by a fresh `record`, which always starts from an empty graph — an edge whose file is confirmed *deleted* can instead be dropped explicitly, one at a time, via `prune --stale-edges`, §11, since "gone from disk" is a plain fact rather than a coverage-attribution artifact), merge of results, `pruneStaleResults` (ids from executed files that no longer appeared: renamed/deleted tests), `pruneMissingTestFiles`, `pruneMissingBranches` (`git for-each-ref`), `complete = true`, `last-run.tree` snapshot of the dirty files.
- Partial / truncated / results-only run: only merges results for already-known test files; no sha, no pruning, no edges.
- Always: recompute `k` for each touched test file and, if there's a remote, `put(objects/<k>.json)`.
- **No edge to a file `git check-ignore` matches.** `Cache\GraphUpdater::apply()` and `Analysis\StaticEdges::expand()` are the only two places an edge is ever written into the graph, and both refuse one whose source is ignored — batched once per `apply()` call over stdin (`Change\Git::ignored()`, shared with `ChangedFiles::since()` itself), never once per file or per writer. Deliberately **IGNORED, not UNTRACKED**: an ignored path can never appear in `ChangedFiles::since()` (§7.1 step 4), so such an edge could never trigger a rerun — it is pure content-key pollution (measured cause: a Laravel compiled Blade view under `bootstrap/cache/`, whose path additionally carries a per-paratest-worker token, so which worker ran a test changed that test's dependency set between two otherwise-identical `record` passes). An **untracked-but-not-ignored** file is the opposite and keeps its edge: it *does* appear in `ChangedFiles::since()` (§7.1 step 3, `--untracked-files=all`), so refusing it would leave `Graph::fileId()` null and `PhpEdgeRule` (§7.2 step 2) skipping it until the next full fresh `record`. Fails open (no git, not a repository, a subprocess error or a timeout) exactly like `Fingerprint::isTrackedByGit()`: the edge is recorded, not discarded, when the check itself cannot run. No configuration governs this — `.gitignore` (and `.git/info/exclude`, the global excludes file) is the one mechanism, matching `Select\ResiduePatterns`' own stated principle that an allowlist of source paths is unsafe by construction.
- **No coverage-derived edge to a Laravel migration, seeder or console command (§4.3.1 "Once-per-process residue"), only when `static_declaration_edges` is on.** `Cache\GraphUpdater::apply()` refuses a `Graph::unionEdges()` write whose source matches `Laravel\OncePerProcessPaths` (`database/migrations/`, `database/seeders/`, `app/Console/Commands/`), leaving the file with no `fileId` — the residue bucket `Select\ResiduePatterns` owns, so a change re-runs everything the graph knows instead of only whichever test a worker process happened to credit. `Analysis\StaticEdges::expand()`'s write is untouched: a test whose own source names one of these files still gets the edge. With the flag off there is no residue net, so the edge is recorded exactly as before — this fix is a no-op with the flag off, by construction, not by convention.

---

## 8. Hermeticity (what Pest doesn't do)

`Hermeticity\Policy::cacheable(testFile, testId): bool` combines:

1. **Attribute** `#[NotCacheable(reason: '')]` on a class or method (read via reflection while recording; persisted in `not_cacheable`).
2. **Globs** in config: `'never_cache' => ['tests/Feature/External/**', 'tests/Browser/**']`.
3. **Automatic quarantine** (`flaky.json`): while merging results, if for a given `testId` the `k` key matches the cached one but the status changed (pass↔fail, pass↔error), an entry `{testId, firstSeen, flips: n}` is recorded. With `flips >= 1` the test always runs and is shown in `phpunit-replay status`. It leaves quarantine via `phpunit-replay prune --flaky` or after N consecutive stable runs (config `quarantine_release_after: 20`).
4. **Optional heuristic** (`hermeticity_heuristics: true`, off by default): if a test's edges include files matching `**/Carbon/**`, `**/Faker/**` without a seed, or the test uses `Http::` without `Http::fake()` (detected via edges to `Illuminate/Http/Client/PendingRequest.php` without a fakes `Factory.php`), it's marked "suspicious" in `status`; it isn't automatically un-cached.

---

## 9. Remote cache (content-addressed)

```php
interface RemoteCache {
    public function get(string $key): ?string;       // null if it doesn't exist
    public function put(string $key, string $body): void;
    public function has(string $key): bool;
}
```

Keys: `graph/<project-key>/<branch>.json` (full baseline graph per branch, uploaded after full runs) and `objects/<k>.json` (results of a test file by content key).

Startup flow with no local graph: `get(graph/<key>/<branch>)` → if missing, `get(graph/<key>/<defaultBranch>)` → reconcile fingerprint and sha ancestry → use it. Per-test-file flow in replay: if a test file is affected but `objects/<k_actual>.json` exists in the remote (another machine already ran that exact content) → it's treated as **replayed-remote** and not run. This is what lets a PR's CI inherit work from a laptop or from another PR with the same files.

v1 implementations: `FilesystemRemoteCache` (any path: NFS, `rclone mount`, shared volume), `HttpRemoteCache` (GET/PUT/HEAD with an optional Bearer token; works against S3/MinIO with presigned URLs or an nginx with `dav_methods PUT`). Config:

```php
// phpunit-replay.php
return [
    'state_dir' => null,                       // null = ~/.phpunit-replay/<key>
    'remote'    => env('PHPUNIT_REPLAY_REMOTE'), // 'file:///mnt/replay-cache' | 'https://cache.example.com/replay/'
    'remote_token' => env('PHPUNIT_REPLAY_REMOTE_TOKEN'),
    'default_branch' => null,                  // null = autodetect (origin/HEAD, init.defaultBranch)
    'watch' => [],
    'never_cache' => [],
    'quarantine_release_after' => 20,
    'laravel' => 'auto',                       // auto|on|off
    'junit_merge' => true,
    'static_declaration_edges' => false,       // §4.3.1: order-independent edges (opt-in, changes every content key)
];
```

Network failures never break the run: a warning is shown and it continues locally.

---

## 10. Laravel integration (optional, auto-detected)

Activated if `class_exists(\Illuminate\Container\Container::class)` and `artisan` exists. The trackers are wired up **once per application instance**, from the `Test\Prepared` subscriber (the app is already booted because `Prepared` fires after `setUp`): it gets `Container::getInstance()`, checks a marker binding `phpunit-replay.armed`, and if it's missing:

- `TableTracker`: `$app['db']->listen(fn (QueryExecuted $q) => foreach (TableExtractor::fromSql($q->sql) as $t) $recorder->linkTable($t))`. `fromSql` only looks at DML (`select|insert|update|delete|with|replace`) and extracts identifiers after `from|into|update|join`, stripping quotes and schema, and discarding `migrations`, `sqlite_*`, `pg_*`, `information_schema*`.
- `BladeTracker`: `$app['view']->composer('*', fn ($view) => $recorder->linkSource($view->getPath()))` — **unless** `$view->getPath()` lies inside `config('view.compiled')` (read fresh on every call, never cached at arm-time: Laravel Parallel Testing rewrites it per worker after arming happens) or, as a fallback when that config cannot be read, inside `SourceScope`'s own fixed noise directories (`bootstrap/cache`, `storage/framework`, `storage/logs`). `Blade::render($string)` and inline/anonymous components give their view object a path under `view.compiled` itself (Laravel writes the raw string there, content-hashed, to let the ordinary view pipeline find it) rather than a real source template, and that path is never linked (§7.3).
- `MigrationTables`: when writing the graph, every test file whose class uses `RefreshDatabase|DatabaseMigrations|DatabaseTransactions` additionally receives **all** tables from all migrations (conservative: any migration can affect any DB test).

None of this requires Pest or touches the user's code.

---

## 11. CLI

```
phpunit-replay run [--filtered|--in-process] [--fresh] [--no-remote] [--explain] [--log-junit=FILE] [--dry-run] [-- <phpunit args>]
phpunit-replay record [--fresh]
phpunit-replay verify [--parallel=N|-p] [-- <phpunit args>]   # full suite + comparison against cache (§12.2)
phpunit-replay status            # graph: files, edges, branches, size, quarantine, fingerprint
phpunit-replay explain <path>    # which tests changing that file would affect, and by which rule
phpunit-replay prune [--flaky] [--branches] [--all] [--stale-edges]
phpunit-replay baseline-path     # prints the state directory (for uploading artifacts in CI)
phpunit-replay push / pull       # manual sync with the remote
```

`run` with no subcommand is the default: `vendor/bin/phpunit-replay -- --testdox`.

Output: PHPUnit prints its own; the wrapper appends a line at the end with a stable (parseable) format, and colors if a TTY:

```
Replay  ✓ 38 executed (31 affected, 7 uncached) · 1202 replayed (14 from remote) · 2 quarantined · baseline main@a1b2c3d · saved 4m12s
```

`--explain` prints, for each affected test file, the rule and the file that triggered it:

```
tests/Feature/AdTest.php        ← PhpEdge  app/Services/Pricing.php
tests/Feature/BillingTest.php   ← Watch    config/billing/plans.php (config/billing/** → tests/Feature/Billing)
tests/Unit/PricingTest.php      ← TestFile tests/Unit/PricingTest.php
```

Environment variables: `PHPUNIT_REPLAY=1` is equivalent to `run`; `PHPUNIT_REPLAY=0` disables it even with the extension registered; `PHPUNIT_REPLAY_MODE`, `PHPUNIT_REPLAY_STATE_DIR`, `PHPUNIT_REPLAY_REMOTE`, `PHPUNIT_REPLAY_RUN_ID`, `PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES` (`1`/`0`, §4.3.1) are internal wrapper→extension variables.

---

## 12. CI

Documented recommendation in the README, same as Pest: **PR CI runs the full suite**; a separate workflow on `main` records the baseline and publishes it to the remote (`phpunit-replay record --fresh && phpunit-replay push`). Developers get an implicit `phpunit-replay pull` when starting with no graph. Optionally, a "fast" PR job with `phpunit-replay run` that reports within minutes, followed by the full job as a gate.

---

### 12.1 Requirements in CI

- Remote cache configured (`PHPUNIT_REPLAY_REMOTE`); without it every ephemeral job would be a full recording.
- Checkout with enough history for the baseline sha to be an ancestor of HEAD (`fetch-depth: 0` or equivalent).
- pcov on the runner to re-record edges for the affected tests.
- The wrapper detects `CI=true` and in that case: it doesn't write the local graph as the branch baseline unless `--allow-ci-baseline` is passed, it uploads only `objects/<k>.json` for the executed files, and it pulls the default branch's baseline.

### 12.2 `verify` mode and the divergence metric

`phpunit-replay verify -- <args>` runs the **full** suite with the extension in `record` mode, and when done compares each real result against the one the cache holds for the same `testId`/`k`. Any difference in result *class* (a cached pass that now fails, or vice versa) is recorded in `divergence.json` (`{testId, k, cached, actual, sha, at}`), automatically quarantined, and printed as:

```
Verify  ✓ 1240 tests · 1198 would replay · 0 divergences · 0 unverified (lifetime: 2 in 143 runs)
```

The four figures answer different questions and are measured over deliberately different populations:

| Figure | Population | Meaning |
|---|---|---|
| `tests` | `partial->results` | Tests this pass executed for real. |
| `would replay` | `partial->results` ∩ `Select\ReplaySet` | Of those, how many a `run` on this same tree would have served from cache instead of executing. Decided off the run list `run` itself builds (§7.2, `Select\RunListBuilder`) against the state as it was before this pass touched anything — **never** by comparing content keys. A property of the tree, so two `verify` passes over an unchanged tree report the same number. |
| `divergences` | tests whose `old.k === new.k` | Results whose status class changed. Deliberately **broader** than `would replay`: it also checks tests `run` would have re-executed anyway, which can only over-report a divergence, never miss one. A cached `fail` recovering is excluded (§6.2 reruns those unconditionally, so it was never replayed). |
| `unverified` | `would replay` minus those `divergences` could check | Of the tests that would have been replayed, how many this pass could not check, because it observed a different dependency set than the cached result was recorded against (`old.k !== new.k`). §6.2's local replay path never compares keys — `k` addresses the remote object store (§9) — so `run` would still serve those cached results: this is the part of `would replay` the pass is not vouching for. Settles to 0 once the graph's edges stop moving. |

`would replay` counts only what the **local** cache would have served: the remote object store (§9) is not consulted, so a test file `run` would have replayed from another machine's object counts here as executing. Consulting it would mean fetching and merging objects — a side effect a measurement must not have — and would make the figure depend on remote state at that instant rather than on the tree; erring low is the safe direction.

`would replay` is decided **before** the PHPUnit child is launched, not reconstructed afterwards: `apply()` (§7.3) rewrites the graph's edges, keys and results in place, and both `RunListBuilder`'s directory walk and `ChangedFiles`'s git diff would otherwise read a working tree the suite (and the generated `.phpunit-replay.xml`) had already changed. A partial CLI selection does not narrow the run list the decision is made against — the figure keeps meaning "would a `run` on this tree have replayed these tests" rather than collapsing to 0 for a `--filter` pass, which §6.1 sends into results-only mode; the selection only narrows which tests the figure is reported over.

This is the full-lane command on `main`/nightly. `lifetime divergences` and `would replay` together are the objective data point for deciding when the fast lane can become a PR gate: the first says whether the cache is right, the second how much of the suite it would actually spare. `status` shows the historical series.

`verify` also accepts `--parallel`/`-p[=N]` (§13): being a full-suite pass, it is the slowest command in the package and the one the README recommends as the actual PR merge gate, so running it through Paratest is what keeps that gate fast.

## 13. Parallel (phase 2)

Paratest support: the wrapper detects `--parallel`/`-p` and launches `vendor/bin/paratest` with the same filtered configuration, for `run`, `record` and `verify`. Workers inherit `PHPUNIT_REPLAY_*` via the environment; each one writes `runs/<run-id>/worker-<TEST_TOKEN>-{edges,results}.json`; the wrapper merges by union (edges) and last-write-wins (results). `-d pcov.directory` is passed to workers via `--passthru-php`.

---

## 14. Implementation phases

**Phase 1 — core (goal: usable on a real project)**
ContentHash, Fingerprint, Git, ChangedFiles, LastRunTree, Graph + GraphStore, SourceScope, pcov/Xdebug drivers, Recorder, ResultCollector + subscribers, ReplayExtension, ReplayState, Selector with PhpEdgeRule + TestFileRule + WatchRule, CLI `run` (filtered) / `record` / `status` / `baseline-path`, Summary, JUnitMerger. Unit tests + 1 plain project fixture.

**Phase 2 — fidelity and ergonomics**
Replayable trait (in-process), `explain`, `prune`, Hermeticity (attribute, globs, quarantine), Laravel (TableTracker, BladeTracker, MigrationTables, SiblingRule, BladeRule), Paratest. laravel-lite fixture.

**Phase 3 — distribution**
RemoteCache filesystem + HTTP, `push/pull`, replayed-remote by `k`, CoverageMerger for `--coverage-php`, hermeticity heuristics, docs and an example GitHub Action.

---

## 15. Tests for the package itself

- **Unit**: ContentHash (comments/whitespace don't change the hash; renaming a variable does), Fingerprint drift structural vs environmental, Graph encode/decode round-trip and hostility (corrupt JSON, mistyped sections), ChangedFiles against a temporary git repo (commit, modify, revert, delete, rename, untracked, ignored), Selector per rule, ResultCollector status precedence, TableExtractor with varied SQL and migrations, Policy and quarantine.
- **Integration** (run real PHPUnit against `tests/Fixtures/Projects/plain` in a tmp dir with git init): (1) first run records the graph; (2) second run with no changes executes 0 and replays everything, exit 0; (3) changing a source file executes only its dependents; (4) a comment-only change executes 0; (5) a failing test is saved and re-run even if nothing changes; (6) `--filter` doesn't touch edges or sha; (7) a different `composer.lock` forces a record; (8) a new test in a known file gets executed; (9) a deleted test gets pruned; (10) in-process mode replays as pass with the original assertions and `--fail-on-risky` isn't triggered; (11) the merged JUnit contains every test; (12) a quarantined test always runs.
- Package CI: `test` job matrix PHP 8.2/8.3/8.4 × PHPUnit 11.5/12/13 × driver pcov/xdebug, excluding cells PHPUnit can't resolve (12.0 needs PHP >=8.3; 13.0 needs PHP >=8.4.1) — 12 of the 18 possible cells. A second `coverage-format` job pins PHPUnit 13.1.14 and forces `phpunit/php-code-coverage` to each of 14.0/14.1/14.2 in turn (3 more cells), because PHPUnit's own version axis resolves `^13.0` straight to php-code-coverage 14.3 and would otherwise leave the other three `--coverage-php` file formats untested (see docs/INTERNALS.md — CoverageFormat). 15 cells total.

---

## 16. v0.1 acceptance criteria

1. On a PHPUnit 11.5, 12, or 13 project without modifying its tests, `vendor/bin/phpunit-replay` on a second run with no changes finishes in < 2 s + bootstrap time, with exit 0 and summary "0 executed, N replayed".
2. Changing a source file executes exactly the test files with an edge to it (verifiable with `--explain`).
3. A test that failed is never replayed.
4. A comments/whitespace-only change executes nothing.
5. Changing `composer.lock` or `phpunit.xml` triggers a full recording with a clear warning.
6. `--filter`, `--group`, `--testsuite` or an explicit path run what was requested and don't corrupt the graph.
7. Without pcov or Xdebug the package disables itself with a warning and PHPUnit works normally.
8. State files are written atomically; a `Ctrl-C` mid-way never leaves a corrupt `graph.json`.

---

## 16b. Reuse of Pest code (MIT)

Pest (`pestphp/pest`, © Nuno Maduro, MIT license) has, in `src/Plugins/Tia/`, a TIA engine that is 60% Pest-agnostic. It is ported by copying with a namespace change, **never** as a Composer dependency (`@internal` classes, drags in the framework and its global functions). Reference: commit `17d709e32bed028005c8e8a825c7161d73af3468` (2026-09-04).

| Pest file (`src/Plugins/Tia/`) | Destination | Action |
|---|---|---|
| `ContentHash.php`, `TableExtractor.php` | `Cache/ContentHash.php`, `Laravel/TableExtractor.php` | copy as-is |
| `FileState.php`, `Storage.php` | `Cache/GraphStore.php`, `Cache/ProjectKey.php` | copy; path `~/.phpunit-replay` |
| `ResultCollector.php` | `Record/ResultCollector.php` | copy as-is (only uses `TestStatus`) |
| `SourceScope.php` | `Record/SourceScope.php` | copy as-is (uses `Registry`) |
| `Fingerprint.php` | `Cache/Fingerprint.php` | copy; replace the structural file list with the one in §4.5 |
| `ChangedFiles.php` + `src/Support/Git.php` | `Change/ChangedFiles.php`, `Change/Git.php` | copy; remove Pest's `MissingDependency` |
| `Recorder.php` | `Record/Recorder.php` | copy; `TestSuite::getInstance()->rootPath` → injected root; `$__filename` → `ReflectionClass::getFileName()` |
| `TestPaths.php`, `WatchPatterns.php`, `WatchDefaults/*` | `Select/…` | copy; inject root; remove `WatchDefaults\Browser` (depends on pest-plugin-browser) |
| `CoverageMerger.php` | `Report/CoverageMerger.php` (phase 3) | copy; remove `Pest\Support\Container` |
| `Graph.php` | `Cache/Graph.php` + `Select/Selector.php` + `Select/Rules/*` | adapt: separate the model (encode/decode/baselines/pruning) from selection (`affected()` and `apply*`); rewrite `archTestFiles()` using reflection over `#[Group('arch')]`; remove `View::render`, `Container`, `TestCaseFactory` |
| `src/Subscribers/EnsureTia*.php` | `PHPUnit/Subscribers/*` | copy; they are standard PHPUnit subscribers; drop `EnsureTiaIsRunningPestTestsOnly` |
| `tests/Unit/Plugins/Tia/*`, `tests/Fixtures/Suites/Tia*` | `tests/Unit/*`, `tests/Fixtures/*` | port from Pest syntax to PHPUnit classes; keep the edge cases |
| `Tia.php` (plugin, 2,300 lines), `Concerns/Testable.php`, `Plugins/Tia/BaselineSync.php`, `JsModuleGraph.php`, `Restarters/*` | — | **do not port**: replaced by `Console/RunCommand`, `PHPUnit/ReplayExtension`, `Replayable`, `Cache/Remote/*` |

Mandatory attribution: a `LICENSE-PEST.md` file with the original MIT text; a paragraph in the README ("Portions derived from Pest, © Nuno Maduro, MIT"); a docblock in every ported file: `@see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/<File>.php`. Don't use "Pest" in the name or imply affiliation.

## 17. Prompt for Claude Code

Copy from here:

```
You are going to create from scratch the Composer package `manuglopez/phpunit-replay` following the attached specification (phpunit-replay-spec.md) to the letter. It is a Test Impact Analysis and result-replay library for PHPUnit 11.5+/12, with no dependency on Pest.

Working rules:
- Before writing anything, clone `https://github.com/pestphp/pest` (checkout of commit 17d709e32bed028005c8e8a825c7161d73af3468) into a temporary directory and port the files according to the table in section 16b of the spec: copy, change the namespace to Manuglopez\Replay\..., remove the indicated Pest dependencies and add the origin @see docblock. Create LICENSE-PEST.md with the original MIT text. Only write from scratch what the table marks as "do not port" or what doesn't exist in Pest (CLI wrapper, ReplayExtension, Replayable trait, remote cache, quarantine, JUnitMerger).
- PHP 8.2+, strict_types in every file, `final` by default, readonly where applicable, PSR-12, PHPStan max level clean.
- Implement Phase 1 completely before touching anything from phases 2 and 3. Within phase 1, this order: Cache/ContentHash → Cache/Fingerprint → Change/Git + ChangedFiles + LastRunTree → Cache/Graph + GraphStore → Record/SourceScope + drivers + Recorder → Record/ResultCollector + PHPUnit/Subscribers → PHPUnit/ReplayState + ReplayExtension → Select/TestPaths + WatchPatterns + Selector (PhpEdge, TestFile, Watch rules) → Console (run filtered, record, status, baseline-path) → Report/Summary + JUnitMerger.
- Each component gets its unit tests before moving to the next. Integration tests use a real fixture project in tests/Fixtures/Projects/plain (with phpunit.xml, src/ and tests/), copied to a tmp dir with `git init` + initial commit, and run real PHPUnit via subprocess.
- Verify against the PHPUnit source code installed in vendor/ (not from memory) the exact signature of: PHPUnit\Runner\Extension\Extension::bootstrap, the PHPUnit\Event\Test\* events used and their methods (test()->id(), test()->file(), numberOfAssertionsPerformed(), wasSuppressed(), throwable()->message()), PHPUnit\TextUI\Configuration\Configuration (source(), testSuite(), failOn*, displayDetailsOn*, hasFilter, hasGroups, includeTestSuite, cliArguments), PHPUnit\Framework\TestCase::runTest, valueObjectForEvents, addToAssertionCount, expectNotToPerformAssertions, and PHPUnit\Framework\TestStatus\TestStatus::asInt(). If anything differs between 11.5 and 12, abstract the difference in PHPUnit/ConfigurationReader.
- The pcov driver must be used via its raw API (\pcov\start, \pcov\stop, \pcov\waiting, \pcov\collect(\pcov\inclusive, $files), \pcov\clear). Check that ext-pcov is available in the environment; if not, install it (pecl install pcov) so the integration tests can run with a real driver, and also run the matrix with Xdebug if available.
- The CLI wrapper must never hide PHPUnit's output or change its exit code. State files are written with tmp + rename. Any error from the package itself (git unavailable, corrupt JSON, remote down) degrades to "run PHPUnit normally with a warning", never to breaking the run.
- README.md in English with: what it is, an honest comparison with Pest 5 TIA and phpunit-tia, installation, the two modes, configuration, CI, known limitations (file-level edges, #[Depends], non-hermetic tests, no merged coverage until phase 3).
- After finishing phase 1: composer validate, phpstan, the whole suite green with pcov, and a short report of what was left out and why. Then continue with phase 2 in the order given in the spec.
```
