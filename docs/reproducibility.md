# Is the dependency graph a function of the tree, or of the run?

Everything this package does rests on one promise: identical content produces an identical
result. A cached result is served because the tree that produced it is the tree in front of
you. So the graph that decides that — which source file each test depends on — has to be a
function of the tree, and of nothing else.

It was not. This document records what was measured, what was fixed, what turns out to be
inherent to shared-process coverage attribution and is therefore documented rather than fixed,
and what is still open.

Every figure below was measured on one real project: a Laravel 13 application, 9,056 tests in
726 test files, 2,115 recorded source files, pcov, 8 Paratest workers. Each pass recorded from
an **empty** state directory with Laravel's compiled-view cache cleared first, on an unchanged
working tree at a fixed commit.

## The measurement

"Differing" means: test files whose dependency set is not identical between two passes, out of
726. Every pairwise comparison of every pass is listed, because with two passes you cannot tell
a random difference from a systematic one.

| configuration | differing test files | `files` per pass | edges per pass |
|---|---|---|---|
| before the fixes (2 passes) | **189** | 2,115 / 2,082 | 61,278 / 61,280 |
| with the ignored-edge fix (3 passes) | **78 / 64 / 67** | 1,834 / 1,834 / 1,834 | 60,572 / 60,569 / 60,569 |
| plus `static_declaration_edges` (3 passes) | **22 / 15 / 14** | 1,799 / 1,799 / 1,799 | 21,706 / 21,688 / 21,687 |

Read the `files` column first. Before the fixes the set of source files the graph even knew
about moved between passes. After, it is identical every time. That is the difference between
a graph that describes the tree and one that describes the run that happened to produce it.

## Why this decides whether the remote cache can work at all

`Cache\ContentKey::forTestFile()` computes a test file's key from the fingerprint, the test
file's own content hash, **and its sorted dependency list**. `Console\Commands\PushCommand`
uses exactly that key to name what it uploads, and `Cache\Remote\ObjectStore::objectKey()`
turns it into `objects/<shard>/<k>.json`.

So two machines that attribute different dependencies to the same test file compute different
keys and cannot find each other's objects. The remote cache does not fail loudly when this
happens — it degrades to a miss. **The viability of sharing a cache between machines is
precisely the reproducibility of the graph**; they are one problem, not two. Which puts a price
on the table above:

| | remote objects unreachable across machines |
|---|---|
| before | 189 of 726 — **26%** |
| with the ignored-edge fix | 64–78 — **9–11%** |
| plus the static flag | 14–22 — **2–3%** |

## What was wrong: edges to files git ignores

281 of the 2,115 recorded "source files" were
`bootstrap/cache/views/test_<N>/<hash>.blade.php` — Laravel's compiled Blade view cache, in a
**per-worker-token directory**, because Laravel Parallel Testing rewrites `view.compiled` per
worker. The same template compiled by worker 1 and worker 5 was recorded as two different
dependencies, so which worker happened to run a test changed that test's dependency set.

`git check-ignore` matched every one of those 281 paths; **zero tracked files** were among them.

The producer was a single line. `Laravel\BladeTracker` links `$view->getPath()` for every
rendered view. For an ordinary template that path is the real source `.blade.php` — correct.
But for `Blade::render($string)` and inline or anonymous components, Laravel hands the view a
path that is its own disposable compiled artifact: `Illuminate\View\Component`
writes the raw string into `config('view.compiled')` itself and
`BladeCompiler::render()` ends by `@unlink()`ing that very path. Livewire-heavy applications
hit it constantly. `Record\Recorder::linkSource()` applies no filtering, deliberately — an
explicit link never went through coverage, so it is not the recorder's to second-guess. That
principle is right for a source template and wrong for a generated artifact.

Fixed in two layers: the tracker refuses a path inside the compiled-view directory, and no
edge is written to any file `git check-ignore` matches, at both of the only two places an edge
is ever written.

The rule is **ignored**, not **untracked**, and the distinction is the whole safety argument.
An ignored path can never appear in a git diff, so an edge to one can never trigger a rerun —
it is pure content-key pollution. But an untracked-but-not-ignored file (a new source file
nobody has `git add`ed yet) **does** appear in that diff and must keep its edge, or the test
that depends on it silently stops being selected until the next full record.

If a path should never become an edge, put it in `.gitignore`. That is the only exclusion
lever, by design: it already composes with `.git/info/exclude` and the global excludes file,
and it fails safe. An allowlist of source roots was considered and rejected — one that omitted
a real root (`Modules/`, `packages/`, a custom `domain/`) would produce exactly the false green
this package exists to prevent.

## What remains, and why neither mechanism can remove it

With `static_declaration_edges` on, 22 files still move between passes:

| kind | count | attributions each |
|---|---|---|
| `app/Console/Commands/*` | 5 | 15 tests |
| `database/seeders/*` | 7 | 11 |
| `database/migrations/*` | 4 | 11 |
| `app/Services/*` | 2 | 13 |
| a factory and a model | 2 | 3 and 1 |

Classified with this package's own `Analysis\DeclarationScanner`: **22 of 22 have function
bodies**; none is declaration-only; none is unparseable. So this is *not* the declaration
first-loader problem the flag exists to fix. These files' bodies genuinely execute — once per
worker **process**. A migration runs once per worker database, a seeder once, a console
command's registration once. Whichever test in that process triggers it first is credited, and
which test that is depends on how Paratest happened to distribute work.

The flag cannot help here, and that is not an oversight: its body filter exists precisely to
**keep** an edge whose target's body lines ran, because that is the reliable signal. Here the
body really did run — just once, under one arbitrary test.

The mechanism was measured rather than inferred, by extracting holder identities from the
graphs. The holder set **shrinks rather than swapping**:

```
app/Console/Commands/BackfillRecoveryLeads.php                129 → 128 → 128 holders
database/seeders/TeamSeeder.php                                18 →  17 →  17
database/migrations/..._create_comando_sellos_table.php         18 →  17 →  17
```

with the same test file dropping out of all three. That is first-runner-wins where the next
pass's winner already held the edge through its own execution, so the set loses a member and
gains none.

**This is not a false green, and the reason is `Graph::unionEdges()`.** A re-record unions its
edges into the graph instead of replacing them, so repeated recordings converge on the superset
(129 ∪ 128 = 129), never the intersection. Under-attribution in one pass degrades to a cache
miss in the next, never to a stale hit. That property is what makes an unavoidable residual
acceptable rather than dangerous.

## The cost of `static_declaration_edges`, corrected

The 0.3.0 changelog gives the flag's cost as "+2.84% (1,781 edges)". That figure is the count
of edges the static hop **adds**. It says nothing about the edges the body filter **removes**,
and on this suite that is the larger half by a factor of twenty:

| | edges |
|---|---|
| flag off | 61,278 |
| flag on | 21,706 |
| net | **−39,572 (−64.6%)** |
| removed by the body filter | 41,488 |
| added by the static hop | 1,916 |

A reader who acts on "+2.84%" expects a graph 3% larger and gets one 65% smaller.

There is a second consequence the flag's documentation never stated. With the flag on, these
leave the graph's `files` table entirely: `config/*.php` (55 files), `bootstrap/app.php`, and
`lang/*.php` (4). `Graph::fileId()` then returns null for them, so `Select\ResiduePatterns`
claims them and `Select\Rules\WatchRule` turns that into "run everything the graph knows". So
**with the flag on, editing any `config/` or `lang/` file re-runs the whole suite**; with it
off those files carry edges to roughly 700 test files each and only those run. On this suite
that is the difference between 700 tests and 9,056.

That behaviour is safe and deliberate — `ResiduePatterns` exists to refuse to guess rather than
drop an edge — but it is a real cost, and turning the flag on is a trade, not an upgrade.

Verified while measuring: **orphans = 0** in both graphs, so the `fileId() !== null ⟺ the file
has an edge` complement that `ResiduePatterns` and `Select\Rules\PhpEdgeRule` partition the
changed set with holds exactly.

## The sequential control: worker assignment was the entire cause

Two **sequential** passes — no `--parallel` at all, so one process, and Paratest's work
distribution removed as a variable:

```
pass 1:  9,056 tests · 70,241 assertions · 1,833 source files · 60,282 edges · 21m21s
pass 2:  9,056 tests · 70,241 assertions · 1,833 source files · 60,282 edges · 21m26s
```

`files`, `edges`, `test_tables`, `not_cacheable` and `fingerprint` are **identical**. Of the
fields on every stored result — status, assertions, message, file and **content key** — only
the wall-clock time differs.

So **every content key is identical across two sequential passes.** For a fixed execution
shape this package's graph is reproducible, and the 14–22 residual measured under 8 workers is
Paratest's work distribution and nothing else — not the package's logic, and not the suite.

That is also what makes the single-producer approach viable: a graph recorded once, in one
shape, and distributed with `push --graph` / `pull` gives every machine the same addresses by
construction, without waiting for convergence.

## The assertion variance: one test, and why

The suite-level assertion total varied across passes — 35,387 / 35,777 / 35,897 / 36,197 /
36,275 / 36,299 in parallel, and 70,241 sequentially, with test, skip and deprecation counts
identical every time (9,056 / 18 / 8). Subtracting one test accounts for all of it:

| pass | total | that one test | the rest |
|---|---|---|---|
| parallel 1 | 36,275 | 3,474 | **32,801** |
| parallel 2 | 36,197 | 3,396 | **32,801** |
| parallel 3 | 35,993 | 3,192 | **32,801** |
| sequential 1 | 70,241 | 37,440 | **32,801** |
| sequential 2 | 70,241 | 37,440 | **32,801** |

The rest of the suite makes exactly 32,801 assertions in every configuration. The test is
`NovaCustomStylesTest::every_registered_style_resolves_to_a_file_that_exists`, and the
mechanism is in the application rather than in either the test or this package:
`Nova::allStyles()` accumulates a registration per application boot and never de-duplicates,
so a method whose body makes 6 assertions (it filters to three expected names, two assertions
each) makes 6 × *boots in this process* — 6 in isolation, ~3,474 in one of eight workers,
37,440 in a single process running all 9,056 tests.

Per-test `assertions` from `--log-junit` is therefore **reliable**, including under Paratest:
every other test's count matches exactly between the parallel and sequential passes. An
earlier reading of this data mistook that one test's real, boot-dependent count for a
log-merging artifact; it is not one.

## Measurement caveat

**A sequential record of this suite needs far more than the CLI default of 512 MB.** One
process keeps every test's state; it exhausted 512 MB at test 6,615 of 9,056 and recorded
nothing. `record` correctly reported `nothing recorded` and exited 2 rather than forwarding a
passing exit code, but the limit has to be raised before a sequential measurement is possible
at all. `PHPUNIT_REPLAY_PHPUNIT_BIN` pointed at a shim that raises `memory_limit` does it
without touching the project.

## Summary

| | status |
|---|---|
| the set of source files the graph knows | **solved** — identical across passes |
| edges to generated, per-worker paths | **solved** — none recorded |
| declaration first-loader attribution | **solved** by `static_declaration_edges`, at a documented cost |
| reproducibility for a fixed execution shape | **solved** — two sequential passes agree on every content key |
| once-per-worker side-effect attribution (migrations, seeders, command registration) | **inherent** to how Paratest distributes work; it is what the 14–22 residual under 8 workers is, it is made safe by edge union, and it disappears entirely when one job records the graph and the rest pull it |
| which tests move the suite's assertion total | **solved** — one test, whose count scales with application boots per process |

## What this means for sharing a cache

Reproducibility is not the open problem it looked like. For a fixed execution shape the graph
and every content key are identical, so the remaining question is not *can* addresses be
stable but *who computes them*. Two measures, in the order they are worth taking:

1. **One producer.** Let a single baseline job record edges and distribute the graph
   (`push --graph` / `pull`); everything else gates against the pulled graph and records
   nothing. Addresses then match by construction rather than by convergence, and the residual
   above stops mattering. This is configuration, not a format change.
2. **Address on what is stable, validate on what is recorded.** Today the dependency list is
   *in* the object's address, so an address moves whenever an attribution does, and the old
   object is not invalidated — it becomes unaddressable, an orphan reclaimed only by
   `prune --remote` on age. Deriving the address from the structural fingerprint and the test
   file's own content, and storing the dependency list (and the environmental fingerprint)
   *inside* the object to be re-checked against the consumer's tree on adoption, makes the
   address stable and the check strictly stronger than it is now: a dependency the producer
   knew about and the consumer does not is currently never checked at all, and would be. This
   changes the remote object format and belongs in its own release.

Two hazards worth knowing while neither is done, both of which silently give one machine
different addresses from another's:

- `Cache\Fingerprint::trackedHash()` returns null for a file git does not track, so an
  **untracked `phpunit-replay.php` or `phpunit.xml`** changes the structural fingerprint and
  therefore every address. Commit them.
- `prune --stale-edges` is opt-in, and it removes dependency edges. Whether a machine has run
  it changes that machine's addresses.
