# Proposal: find a result by what it was computed from, not by where it was recorded

**Status: accepted; partly implemented.** The owner's decisions are recorded in "Decisions (2026-10-01)" below. v0.12.0 implemented the local half: every result is stamped with its content key and a digest of its non-edge inputs, and is served only while both match; a remote object holds up to 20 digest variants; the graph is published only from a clean tree. Still pending: the remote `v2/` layout (candidates found by test file and content, verified against the worktree), environment as result metadata with a consumer policy, normalised vendor inputs, `c2` hashes, and `composer.json` sections in the fingerprint. Line references below are to `main` at `0a96cef` (v0.10.0). The measurements come from one real project: a Laravel 13
application, about 9,000 tests in 726 test files, sharing its cache through a dedicated git
repository that held 3 branch graphs and 6,257 objects when it was measured. Anything this
document states without a line reference or a measurement is marked **unverified**.

The question this answers was put by the project's owner, and it is worth keeping in its own
words: *can the objects be unique and replicable across machines, reusable whatever the OS or
the versions? If each test's data is stored, that shouldn't matter. What teams need is to be
able to find those objects.* The teams in question use macOS, Linux and Windows.

The short answer has two halves, and the rest of the document is the argument for each:

- **For code, yes.** An object can carry, next to its result, the full list of what the result
  was computed from, each with a content hash. Any machine can then check that list against
  its own working tree. It needs no branch, no ancestor commit and no graph to do so, only the
  files in front of it. Finding the object needs nothing more than the test file itself.
- **For the environment, not for free.** PHP version and OS are inputs to an outcome, not
  details of it. They move out of the address and into the result, where each machine that
  ran the test adds its own entry. The consumer then decides which environments it will
  accept. Relaxing that choice is a team decision that has to rest on a measurement, and this
  document includes the protocol for taking it.

## The problem

### What it takes to find a result today

A remote object is `objects/<yyyy-mm>/<k>.json`, holding the results of one test file
(`Cache\Remote\ObjectStore.php:16-18`, `:94-97`). `k` is a hash over four things
(`Cache\ContentKey.php:41-64`):

```php
// src/Cache/ContentKey.php:58-61
$material = Fingerprint::canonicalStructural($fingerprint)
    . Fingerprint::canonicalResultEnvironment($fingerprint)
    . $testHash
    . implode('', $parts);          // sorted "dependency:ContentHash(dependency)"
```

Each of the four has to be computed by the machine that wants the result. Here is what each
one costs it:

| part of `k` | where the consumer gets it | what that requires of the consumer |
|---|---|---|
| the test file's hash (`:43`) | its own working tree | nothing |
| each dependency's path and hash (`:51-54`) | `Graph::dependenciesOf()` (`ContentKey.php:66-69`, `Graph.php:192-203`) | **a graph**, and that graph's edges for this file must be *exactly* the recorder's |
| the structural bucket (`:58`) | `composer.lock`, `phpunit.xml`, `phpunit.xml.dist`, `phpunit-replay.php`, hashed whole (`Fingerprint.php:143-148`, `:185-187`) | byte-for-byte the same four files as the recorder |
| `php` and `os` (`:59`) | `PHP_MAJOR_VERSION.PHP_MINOR_VERSION`, `PHP_OS_FAMILY` (`Fingerprint.php:151`, `:202`, `:204`) | the same PHP minor and OS family |

The second row is the one that shapes everything else. A graph arrives in one of two ways. It
can be recorded locally, which means running the suite. Or it can be adopted from
`graph/<project-key>/<branch>.json`, which means some branch's baseline has to be published,
has to pass `structuralMatches()` (`Fingerprint.php:220-229`), and its sha has to be an
ancestor of `HEAD` (`Change\BaselineResolver.php:58`). The ancestry check needs history, which
is why CI checkouts need `fetch-depth: 0`. When no baseline qualifies, the pass records from
scratch, and remote objects play no part in that (`Console\Runner\RunPipeline.php:1044-1050`;
the only call site of `replayFromRemote()` is `:1074`, on the replay path).

So a result is content-addressed, but the only door to it is a branch graph.

### What one real project measured

| measurement | value |
|---|---|
| content keys shared by any two of the 3 branch graphs | **0** |
| outcomes identical among the tests those graphs have in common | **100%** |
| objects in the remote no published graph can address | **61.6%** of 6,257 |
| objects written per push that are identical in outcome to an earlier object of the same test file | **87–99.9%** |
| objects rewritten by every push after a `composer.lock` change | ~800 |

Read the first two rows together. The branches disagreed on no outcome, yet shared no
address. They had different `composer.lock` files, and the whole-file hash of the lock sits in
every `k`. The address is more specific than the outcome is.

The third row is the cost of "only a graph opens the door". An object recorded on a developer
laptop or on a PR branch that never published a graph is unreachable for everyone else, even
for a colleague who has exactly that content checked out.

The fourth and fifth rows are the storage cost of the first. Every lock bump writes a new
generation of objects that mostly say what the old generation said.

### Two defects the new design must not inherit

**F1: remote replay serves test files that a non-edge rule selected.** Being fixed separately
on `fix/remote-replay-only-edge-selected`. The selection chain has rules that select a test
file *without* an edge: Migration, Sibling, Blade and Watch (`Select\Selector.php:30-45`), and
with `static_declaration_edges` the residue fallback (`Select\ResiduePatterns.php:89-117`,
installed at `Select\RunListBuilder.php:52`). All of them exist because the triggering file is
*not* in the test's dependency list. `replayFromRemote()` accepts any file in the selection
(`RunPipeline.php:1355`, `Selection::has()` at `Select\Selection.php:36-39`) and looks it up by
a `k` computed from the edges alone (`:1361-1364`). The triggering file is not in `k`, so `k`
has not changed, the old object is found, and the test is served green. The in-process path
does the same (`PHPUnit\ReplayState.php:880-904`).

The sharpest instance is the residue itself. With `static_declaration_edges` on, a migration,
seeder or console command gets no coverage edge at all (`Cache\GraphUpdater.php:133-134`). A
change to one is meant to re-run every test the graph knows. Instead, every one of those files
is looked up by an unchanged `k` and served from the remote.

**F2: results served from a branch's own layer when the diff was taken against another
baseline.** Results are layered: the branch's own over the nearest baseline over the default
branch (`Cache\Graph.php:446-461`, `:469-480`). The resolver can pick a baseline other than
the branch's own, when it is fewer files away (`BaselineResolver.php:77-79`). The diff is then
taken from that baseline's sha, while a result the branch recorded itself, at its own older
sha, still wins the layering. A dependency that differed between the branch's old sha and the
chosen baseline, but not between the baseline and `HEAD`, is invisible to the diff, and the
own-layer result recorded against the old content is served. The window is narrow, and it is
real.

Both defects have the same shape. **A result is trusted because of where it sits** (in a
selection, in a layer) **rather than because of what it was computed from.**

## The idea

Make every object carry what it was computed from, with a content hash for each input. Index
objects by the one thing every consumer has without help: the test file's path and content.
A consumer fetches the candidates for its test file and keeps one only if **every recorded
input matches its own working tree**. There is no address to agree on beyond the test file,
so there is nothing for a branch, a commit or a graph to supply.

The environment leaves the address. An object is identified by its code inputs. Each
environment that runs it adds a result entry, and the consumer chooses which environments it
accepts.

## The model

### Three kinds of thing

| thing | key | mutable? | what it holds |
|---|---|---|---|
| **candidate** | `id = xxh128(canonical JSON of its inputs)` | no | the inputs one recorder observed for one test file at one content: dependencies with hashes, scopes, vendor inputs, structural inputs, recording flags. Plus the result entries attached to it |
| **bucket** | `v2/<project-key>/tests/<pd[0:2]>/<pd>/<th>.json`, with `pd` the digest of the test file's path and `th` its content hash | merge-only (entries are added, never edited) | every candidate anyone has published for that test file at that content |
| **universe** | `v2/<project-key>/universes/<ud>.json` | no, content-addressed | the recorder's list of *known* files (the graph's `files` table) at recording time. Scopes are defined relative to it (see "Non-edge inputs") |

The bucket is the index the owner asked for. It is keyed by the test file, it lists candidate
objects, and the consumer verifies each candidate against its own worktree.

### Picture

```
  publisher (any machine, any OS)                     consumer (any machine, any OS)
  ran tests/Feature/CartTest.php                      has tests/Feature/CartTest.php on disk
  observed: deps, scopes, vendor, results             th = ContentHash2(the test file)
            │                                                   │
            │ merge-append                                      │ GET (one per test file)
            ▼                                                   ▼
   v2/<project>/tests/<pd[0:2]>/<pd>/<th>.json     pd = digest("tests/Feature/CartTest.php")
   ┌────────────────────────────────────────────────────────────────────────────────┐
   │ candidate 7f3a…                                                                │
   │   deps     app/Cart.php=c2:91e0…  app/Money.php=c2:04b2…  (~30 with the flag)   │
   │   scopes   watch:config/**@1=5d1c…  residue@1=a90f…     universe=e41b…         │
   │   vendor   laravel/framework=3c1e…  brick/money=88d0…  …                        │
   │   results  8.4/Linux/lf   ci   2026-09-29  {testAdd: pass, testRemove: pass}    │
   │            8.4/Darwin/lf  dev  2026-09-30  {testAdd: pass, testRemove: pass}    │
   │ candidate 0c9d…  (another recorder, a different dependency list)               │
   └────────────────────────────────────────────────────────────────────────────────┘
            │
            ▼  for each candidate, against the consumer's own worktree:
   every recorded hash equal? every scope digest equal? vendor equal? structural equal?
   does it cover every dependency the consumer itself knows for this test file?
   is there an entry from an accepted environment, do all such entries agree,
   and does none of them force a re-run?
            yes → replay            no → next candidate;  none left → run the test
```

No step reads a branch, a commit, a graph published by someone else, or git history.

### A bucket on disk

```json
{
  "layout": 2,
  "file": "tests/Feature/CartTest.php",
  "test_hash": "c2:5e1f…",
  "candidates": {
    "7f3a…": {
      "inputs": {
        "hash": "c2",
        "structural": {
          "phpunit_xml_dist": "c2:…", "replay_config": "c2:…", "composer_json": "c2:…",
          "edges_exclude_ignored": true, "edges_by_running_class": true,
          "static_declaration_edges": true, "analysis_rules": 3
        },
        "deps": {"app/Cart.php": "c2:91e0…", "app/Money.php": "c2:04b2…"},
        "behavioural": ["app/Cart.php"],
        "tables": ["carts", "users"],
        "universe": "e41b…",
        "scopes": {"watch:config/**@1": "5d1c…", "residue@1": "a90f…", "migrations@1": "77aa…"},
        "vendor": {"mode": "packages", "packages": {"laravel/framework": "3c1e…", "brick/money": "88d0…"}}
      },
      "results": [
        {
          "env": {"php": "8.4", "os": "Linux", "eol": "lf"},
          "meta": {"php_patch": "8.4.23", "driver": "pcov", "ext": "d1…", "icu": "74.2", "tz": "UTC"},
          "by": {"kind": "ci", "id": "…"},
          "at": "2026-09-29T10:00:00Z",
          "generator": "manuglopez/phpunit-replay 0.x",
          "run": {"workers": 8},
          "tests": {"Tests\\Feature\\CartTest::testAdd": {"s": 0, "a": 3, "t": 0.12, "m": ""}}
        }
      ]
    }
  }
}
```

`deps` holds every dependency, behavioural or static. `behavioural` names the ones coverage
reported, because the consumer recomputes the static hop from them (see "Under-attribution").
`tests` uses the short result keys graphs already use (`Graph.php:1073-1088`).

### Lookup

For each test file `T` the consumer is about to run:

1. Skip lookup, and run, for everything that must run whatever the cache says: unknown to the
   project's own test paths, quarantined here, `never_cache`, a file this machine's graph marks
   not cacheable. These are the same exclusions `replayFromRemote()` makes today
   (`RunPipeline.php:1350-1355`).
2. `th = ContentHash2(T)`. `GET` the bucket, from the local mirror first. When the mirrored
   copy yields no valid candidate, fetch it again once in the same run, because buckets grow.
3. For each candidate, newest result first, apply the validity rule of the next section.
   Every hash is computed at most once per run and path, and the scope digests once per
   (scope, universe), since candidates share them.
4. Replay from the first valid candidate whose accepted result entries agree and hold no
   status that forces a re-run (the rule `holdsARerun()` applies today, `RunPipeline.php:1394-1403`).
   Otherwise, run `T`.

This works the same whether or not a local baseline exists. That directly delivers something
INTERNALS already promises and the code does not do (`docs/INTERNALS.md`, "Pipeline changes":
*"record fresh but replay-remote by `k` still applies"*): a pass whose baseline is unusable
still replays whatever the remote proves unchanged.

### Publish

After a pass, for each test file this machine executed, it merges into the file's bucket:

- the candidate for what it observed, if the bucket does not already hold that `id`; and
- one result entry for this environment.

When the candidate already exists (same inputs, another machine), only the entry is added.
Across branches whose only difference was a vendor package no test loads, that is the whole
write. This is where the ~800-object rewrites go away (see "Vendor inputs").

A machine with no coverage driver consumes but never publishes, because it cannot observe
dependencies. Test files marked not cacheable are never published, as today
(`RunPipeline.php:1430`).

## When a hit is sound

### The rule

A candidate `c` is **valid** for test file `T` in this working tree iff all of these hold:

1. `ContentHash2(T)` equals the bucket's `test_hash`. This holds by construction of the key.
2. For every `(path, hash)` in `c.deps`, `ContentHash2(path)` in this tree equals `hash`. A
   recorded dependency that is missing here contributes the empty hash and fails, as today
   (`ContentKey.php:52`).
3. Every structural input equals this tree's.
4. Every scope digest in `c.scopes` equals the digest of the same scope computed over this
   tree, relative to `c.universe`.
5. The vendor inputs match what is installed here.
6. **Cover:** every dependency this machine knows for `T` from any source (its own graph, a
   pulled graph, the static hop recomputed here) appears in `c.deps`.
7. The scope ids and the hash version are ones this build understands. An unknown one is
   treated as a mismatch, never skipped.

A **hit** additionally needs a result entry from an accepted environment (see "Environment"),
agreement among all accepted entries of all valid candidates, and no entry that forces a
re-run.

### Every input to an outcome, and where each is covered

"Covered" means that if the input differs between recorder and consumer, the hit is refused.

| # | input to a test's outcome | today (v0.10.0) | in this model |
|---|---|---|---|
| 1 | the test file | `ContentHash` in `k` (`ContentKey.php:43`) | the bucket key, rule 1 |
| 2 | source files the test executed | edges in `k` (`:51-54`) | `c.deps`, rule 2 |
| 3 | declaration-only and load-once files credited to another test in the same process (first-loader) | `Graph::unionEdges()` grows edges across passes (`Graph.php:129-148`); the static hop with the flag | rule 6: the candidate must cover the consumer's graph and the static hop recomputed locally |
| 4 | once-per-process bodies: migrations, seeders, console commands | flag on: no edge, residue fallback (`GraphUpdater.php:133-134`, `ResiduePatterns.php:89-117`). Flag off: first-loader edges | `residue@1` and `migrations@1` scopes, rule 4 |
| 5 | files no test has an edge to but a rule maps to tests: watch patterns, residue, siblings, Blade ancestors, migrations by table | diff-driven rules (SPEC §7.2). Remote replay ignores them: **F1** | scopes, rule 4 |
| 6 | files no rule maps to (README, docs) | deliberately affect nothing (SPEC §7.2 item 7) | same, deliberately |
| 7 | `phpunit.xml`, `phpunit.xml.dist`, `phpunit-replay.php` | whole-file hashes in `k` (`Fingerprint.php:143-148`) | structural inputs, rule 3 |
| 8 | vendor code | whole `composer.lock` hash in `k` (`Fingerprint.php:144`) | vendor inputs, rule 5 (see "Vendor inputs") |
| 9 | root `composer.json` `autoload`, `autoload-dev`, `extra` | **not covered**: `composer.json` is not a structural file (`Fingerprint.php:143-148`) | a normalised digest of those sections as a structural input |
| 10 | edge semantics: flags and analysis rules version | named structural keys (`Fingerprint.php:179-192`) | structural inputs, rule 3 |
| 11 | PHP minor, OS family | in the address (`ContentKey.php:59`) | result entry `env`, consumer policy |
| 12 | line endings of the bytes the test read | only indirectly: raw hashes differ for non-PHP files (`ContentHash.php:46`) | `env.eol`, consumer policy (see "Windows") |
| 13 | PHP patch, extensions and their versions (intl/ICU especially), `precision`, `serialize_precision`, `date.timezone`, locale | **not covered** | recorded in `meta`; a policy can be made to require them (open question 2) |
| 14 | coverage driver | deliberately out of the address (`Fingerprint.php:285-290`) | `meta.driver`, not matched |
| 15 | ignored or untracked-but-private configuration (`.env`, local overrides) | **not covered**: ignored files never reach the diff (SPEC §7.1 step 4) | **not covered**, same boundary. Hermeticity levers: `#[NotCacheable]`, `never_cache`, quarantine |
| 16 | external services, clock, randomness, network | hermeticity levers only | same, plus cross-publisher disagreement (see "Trust") |
| 17 | test order and process-shared state | quarantine on a flip (SPEC §8) | same, plus disagreement; `run.workers` recorded |
| 18 | code run in a subprocess the test spawns | **not covered**: coverage does not follow child processes | same boundary, for edges and for vendor inputs |

Rows 9 and 13 are gaps today, not just in this proposal. Row 9 is closed by the design. Row
13 is recorded so that it can be measured before anyone relies on it.

The claim, then: **for rows 1–10 a hit in this model is at least as strict as today's diff
path on a machine with its own graph, and strictly stricter than today's remote replay,
which fails row 5. Rows 11–13 are exactly as strict as the consumer's policy. Rows 15–18
are unchanged.**

### Non-edge inputs: they become part of the object (the F1 decision)

F1 allows two fixes. Either non-edge inputs become part of what an object records, or files
selected by a non-edge rule are never served from the remote. The in-flight fix takes the
second route for the diff path, and that is right for v0.10.x.

**In this model, only the first is possible.** "Selected by a non-edge rule" is a statement
about a *diff*, and the branchless lookup has no diff: no baseline, no sha, nothing to diff
against. A rule that cannot be evaluated cannot guard anything. So the model has no remote
lookup until non-edge inputs are recorded, and it records them as **scopes**.

A scope is a named, versioned, portable file-set predicate. Its members are computed from the
consumer's own working tree: `git ls-files -co --exclude-standard`, run once per pass, minus
test files (`Select\TestPaths.php:80`) and minus the candidate's universe. The object stores a
digest over the sorted `(path, ContentHash2)` pairs of the members. Excluding the universe is
what reproduces today's precision. A watch rule only sees files that are *unknown to the graph*
(`Select\Rules\WatchRule.php:22-48` runs last, on what earlier rules did not consume), and a
file some other test has an edge to is consumed by `PhpEdgeRule` first
(`Select\Rules\PhpEdgeRule.php`).

| scope id | today's rule | members, per this tree | which test files carry it |
|---|---|---|---|
| `watch:<pattern>@1` | `WatchRule` with defaults and user patterns (`Select\WatchDefaults\Laravel.php:26-35`, SPEC §7.2.6) | files matching the pattern (`WatchPatterns::matches()`, `WatchPatterns.php:64`), not in the universe | every test file the pattern maps to (`WatchPatterns::testsUnderDirectories()`, `:106`) |
| `residue@1` | `ResiduePatterns`, flag on only (`ResiduePatterns.php:108-117`) | `.php`, not `.blade.php`, not a test file, not in the universe, minus migrations `migrations@1` owns | every test file |
| `migrations@1` | `MigrationRule`, Laravel, only when the recording had tables (`Laravel\Rules\MigrationRule.php:28-61`) | migrations not in the universe whose extracted tables intersect the test file's recorded `tables`. A migration that extracts no table falls to `watch:database/migrations/**` as today | that test file |
| `sibling:<dir>@1` | `SiblingRule` (`Laravel\Rules\SiblingRule.php:21-30`) | `.php` files in `<dir>`, not in the universe | test files with a dependency in `<dir>` |
| Blade ancestors | `BladeRule` (`Laravel\Rules\BladeRule.php:27-61`) | not a scope of its own: covered by the broader `watch:resources/views/**@1` | — |

Three properties make this sound, and one makes it slightly worse than the diff path.

- **It is a function of the tree plus the object.** The scope definitions come from
  `phpunit-replay.php`, a structural input (rule 3). The universe travels with the object. So
  two machines that pass rules 1–3 compute the same membership.
- **Additions, deletions and edits all change a digest.** A new file matching a pattern joins
  the member set. A deleted one leaves it. Both are what the diff would have listed.
- **It is a conjunction.** The rule chain is ordered and consumes files. The scopes are all
  checked. A file two rules could claim invalidates the object through both, which is at least
  as strict.
- **Precision is lost in two places.** A new Blade partial invalidates every object through
  the watch scope, where `BladeRule` would have narrowed it to its ancestors' dependents. With
  the flag on, a new file in a sibling directory also invalidates everything through
  `residue@1`. Both cost misses, never a false green. The `@1` version suffix exists so that
  either can be refined later without a format change (rule 7).

Why a universe rather than "files not in this object's `deps`": the latter would put every
source file some *other* test depends on into every object's residue, and any source edit
anywhere would invalidate the whole suite. The universe is about 100 KB at this project's
size (1,799 files with the flag, `docs/reproducibility.md`), content-addressed, and shared by
every candidate one recording produced.

### Under-attribution: the cover rule

PHP runs a file's top level once per process, so a declaration-only file is credited to
whichever test in that process loaded it first. The others get no signal at all
(`docs/reproducibility.md`, "What remains"). `Graph::unionEdges()` exists because of this
(`Graph.php:113-148`): it only lets edges grow, so under-attribution in one pass becomes a
miss in the next rather than a stale hit.

Today's address gets a quiet benefit from this. **The consumer computes `k` from its own
dependency list**, so a recorder that under-attributed produces a `k` that a consumer with a
richer graph never computes. The miss protects the consumer.

A self-describing object reverses the direction. **The consumer checks the recorder's list.**
If the recorder missed dependency `d`, rule 2 never looks at `d`, and a changed `d` goes
unseen. That is why rule 6 exists:

> `c` is valid only if `L(T) ∪ S(T, c) ⊆ keys(c.deps)`, where `L(T)` is every dependency any
> graph on this machine holds for `T` (its own, or a pulled one), and `S(T, c)` is the static
> hop recomputed here: files declaring a name that `T`'s own source names, and declaration-only
> files named by `c.behavioural` (the rule in `Analysis\StaticEdges.php:45-48`). `S(T, c)` is
> empty when the candidate was recorded without `static_declaration_edges`.

It is `⊆`, not "unknown means match", because a dependency the candidate never recorded has
no recorded hash. Nothing then says whether this tree matches the recording for it, and
refusing is the only sound answer.

**What it costs, against today:** today's remote lookup needs `c.deps = L(T)` exactly, since
`k` must be equal. The cover rule needs `c.deps ⊇ L(T)`, which is strictly more permissive in
the safe direction. So the cover rule refuses nothing today's lookup would have accepted. The
test files it refuses are the ones whose dependency sets differ between recorders, and those
are unreachable today as well: 2–3% of test files between machines with
`static_declaration_edges`, 9–11% without (`docs/reproducibility.md`, the "remote objects
unreachable across machines" table).

**What it leaves open:** a consumer with *no graph at all* has `L(T) = ∅`. Today such a
consumer can address nothing and runs everything. That means zero exposure and zero benefit.
Here it would rely on the recorder's list plus `S(T, c)`. The static hop is order-independent
by construction, and with the flag on it closes the declaration-only class. In an earlier
measurement on the same project, not recorded under `docs/`, static name resolution reached 60
of 62 class-like declaration-only files. Once-per-process bodies are the residue scope, not
edges. What remains are the files `docs/reproducibility.md` records as moving with no
explained mechanism: two service classes on that project. So the proposal is:

- **A consumer with no graph accepts only candidates recorded with `static_declaration_edges`
  on** (a structural input, so it is visible in the candidate), and recomputes `S(T, c)`
  itself.
- Without the flag, a graph-less consumer must first pull a graph as a source of `L(T)`. The
  default branch's tip is enough, because edges are a property of the tree, and union with a
  graph of a different commit only adds dependencies to cover: more misses, never fewer
  checks. Failing that, it runs.

### What this model trusts that the address did not

The same reversal applies to bugs and to publishers. A recorder that ran with coverage
disabled, or that crashed partway through, would publish a short dependency list. Today that
list yields a `k` nobody else computes. Here it would verify easily. Three guards, all on the
publishing side and all cheap:

- no publishing without a coverage driver, as already stated;
- no publishing from a truncated pass, which already records no edges (SPEC §7.3);
- a candidate's `deps` is the publisher's full graph entry for `T` (after its own union), not
  only what this pass observed. Longer lists are safer lists.

## Environment: an attribute of the result, chosen by the consumer

### PHP version and OS are outcome inputs

This needs saying before offering to relax them, because the offer is only honest if the cost
is on the table. Some concrete mechanisms:

- **PHP minor.** Each minor adds deprecations, and a project that converts deprecations to
  exceptions, or fails on them, turns a pass into an error. Well-known examples: passing
  `null` to non-nullable internal parameters (8.1), dynamic properties (8.2), implicitly
  nullable parameter types (8.4). Minors also change behaviour outright: string-to-number
  comparison and stable sorting both changed in 8.0. Even without a failure, the status this
  package records moves from `0` (passed) to `4` (deprecation)
  (`Record\ResultCollector.php:194` stores PHPUnit's `TestStatus::asInt()`).
- **OS.** Case-insensitive filesystems are the default on macOS and Windows: `file_exists()`,
  `include` and a PSR-4 autoload with the wrong case succeed there and fail on Linux.
  `PHP_EOL` is `"\r\n"` on Windows. `DIRECTORY_SEPARATOR` leaks into generated paths and
  snapshots. `chmod` is mostly a no-op on Windows. `pcntl` does not exist there. Locale names
  differ between platforms.
- **Extensions and their libraries.** An ICU version changes `NumberFormatter`, `Collator` and
  date formatting output. A missing extension turns an executed test into a skipped one,
  which is a different status.
- **Floating point to string.** `precision` and `serialize_precision` govern what `echo`,
  `var_export` and `json_encode` print for a float.

Each of these produces a **deterministic** difference: the same test, the same code, a
different outcome, every time. Under a relaxed policy a deterministic difference is not an
occasional false green. It is a guaranteed one for that test, on every run, on the machines
the policy relaxed for. That fact sets the thresholds below.

### The policy

Each result entry carries `env = {php: "MAJOR.MINOR", os: PHP_OS_FAMILY, eol: "lf"|"crlf"}`.
A consumer declares which entries it accepts:

| policy | accepts an entry when | intended for |
|---|---|---|
| `exact` (default) | `php`, `os` and `eol` all equal this machine's | CI, always, and anyone who has not measured |
| `php_minor` | `php` equal; any OS, any line endings | laptops of a mixed-OS team, after the measurement shows zero unexplained OS differences |
| `any` | any entry | a local accelerator only, with CI running `exact` as the gate |

Two refinements make the relaxed policies usable without making them dangerous:

- **`environment_sensitive` globs** name test files that are always matched `exact`, whatever
  the policy. The measurement produces this list.
- **The policy is per consumer, not per remote.** A developer on macOS who accepts a Linux CI
  entry gets a fast local loop. The PR still runs in CI under `exact`, so the relaxed read never
  becomes the last word on a merge. This is the realistic shape of `php_minor` and `any`: they
  speed up the inner loop, and they do not replace the gate.

`exact` differs from today in one respect: `eol` is new (see "Windows"). `php` and `os` keep
exactly the meaning they have in `Fingerprint::canonicalResultEnvironment()`
(`Fingerprint.php:311-326`).

### What an entry records beyond `env`

`meta` is recorded but never matched by the three policies: PHP patch version, a digest of
the loaded extensions and their versions (excluding `pcov` and `xdebug`, for the reason the
driver is out of the address, `Fingerprint.php:285-290`), the intl ICU version, `date.timezone`,
`precision`, `serialize_precision`, the coverage driver, and the worker count. It is there so a
disagreement can be *explained* (two entries for the same candidate that differ, and whose
`meta` differs only in ICU, is a finding), and so a stricter `exact` can be defined later from
evidence rather than guessed now.

## Vendor inputs

Vendor code has no edges. `vendor` is top-level noise to the source scope
(`Record\SourceScope.php:20`), and `Paths::relative()` refuses any path under it
(`Support\Paths.php:84`). Today the only thing standing for all of vendor is the whole-file
hash of `composer.lock` (`Fingerprint.php:144`, hashed raw by `ContentHash.php:46`). That is
sound, and it is the direct cause of "0 keys shared across branches".

| option | recorded per candidate | sound? | sharing across a lock change |
|---|---|---|---|
| **A. whole lock** (today) | one hash of the file's bytes | yes, as far as the lock decides vendor | none |
| **A′. normalised lock** | a digest of `name → version@reference` over `packages` and `packages-dev` | as sound as A: it drops only fields that do not change installed code (`content-hash`, `time`, URLs, checksums, descriptions) | only metadata-only lock changes. Probably rare; measured by script 2 below |
| **B. per-package, loaded by this test** | packages whose files this test caused to load | **no**: a package an earlier test in the same process loaded is invisible to later ones. This is the first-loader problem again, and for vendor it is severe, since the framework loads once per process | high, and wrong |
| **C. per-package, loaded by the end of this test** | `name → reference` for every package with a file in `get_included_files()` when the test finishes | **yes, for code run in-process**: anything the test executed was loaded before it finished | every lock change touching only packages no test had loaded by then. Measured by script 2 |
| **D. grouped** (runtime vs dev, framework families) | a digest per group | only as sound as its grouping. By `require` vs `require-dev` it is **not**: test tooling and its dependencies are dev packages and are loaded | modest |

Why C avoids the first-loader trap that B falls into: B asks "what did *this test* cause to be
loaded", which is a delta and inherits every ordering accident. C asks "what was loaded in this
process by the time this test ended", which is cumulative, so it is a superset of everything
the test could have executed, whatever the order. The price is that late tests in a process
accumulate most of the runtime packages. For those tests C degrades toward A′, which is safe.
C is order-dependent in *which* superset it records, so two recorders can produce two
candidates for the same content. The bucket holds both, and either verifies.

The set is cheap to maintain: `get_included_files()` returns files in inclusion order, so only
the new tail needs mapping to a package after each test. Packages are verified against what is
**installed** (`vendor/composer/installed.php`, what `Composer\InstalledVersions` reads), not
against the lock. That also closes a case today's hash misses: a checkout whose `vendor/` does
not match its lock because someone forgot `composer install`.

Three edges of C, stated plainly:

- **A package with no immutable reference** (a `path` repository, a `dev-*` version without a
  reference) cannot be verified by reference. A candidate that loaded one records A′ instead.
- **Vendor patched after install** (for example with a patch plugin) keeps its reference and
  changes its code. The declaration lives in `composer.json` `extra`, which row 9 of the table
  makes structural. The patch files themselves need to be a watch scope (`patches/**`). The
  package cannot know every plugin, so this belongs in the documentation as advice.
- **Subprocesses.** A test that runs `php artisan …` in a child process loads packages this
  process never sees. This is the same boundary edges already have (row 18).

**Recommendation.** Ship A′ with the new layout, because it is exactly as sound as today and
needs no instrumentation. Implement C once script 2 below shows how many real lock changes it
would absorb. Reject B, which is unsound, and D, which is either unsound or no better than A′.
Add the normalised `autoload`, `autoload-dev` and `extra` sections of `composer.json` as a
structural input in either case.

## Windows, macOS and portable identity

### Line endings: normalise for identity, record for the outcome

With `core.autocrlf=true`, the option Git for Windows' installer preselects, an identical commit
has different bytes in the working tree. What that does to today's hashes depends on the file
type (`Cache\ContentHash.php`):

| file type | today | effect of CRLF |
|---|---|---|
| `.php` | tokens minus whitespace and comments (`:49-72`, the filter at `:61`) | **none**, unless the file has a multi-line string literal, a heredoc or nowdoc, or inline HTML: those tokens keep their `\r` |
| `.blade.php` | whitespace collapsed (`:74-80`) | none |
| JS-like | whitespace collapsed (`:82-89`) | none |
| everything else: JSON, YAML, XML, SQL, CSV fixtures, `composer.lock`, `phpunit.xml` | raw bytes (`:46`) | **every hash differs** |

The last row means that today a Windows checkout computes a different structural bucket for
`composer.lock` and `phpunit.xml` than a Linux checkout of the same commit. It is a different
project to the cache, **before the environment is even considered** (**unverified** on a real
Windows machine; follows from `Fingerprint.php:391` → `ContentHash.php:46`).

Two ways to make identity portable:

| | normalised content hash ("`c2`") | git blob id |
|---|---|---|
| definition | today's `ContentHash`, applied after `"\r\n" → "\n"`, skipped for content with a NUL byte in its first 8,000 bytes (git's own binary heuristic) | `git hash-object --stdin-paths`, which applies the repository's clean filters and `.gitattributes` |
| keeps today's comment and whitespace insensitivity for PHP, Blade and JS | **yes** | no: a comment edit becomes a miss |
| needs git | no | yes, one batched process per pass |
| agrees with git on exotic attributes (`eol=crlf` files, custom filters) | not always. Disagreement only splits identity, and a split is a miss | yes |

**Recommendation: `c2`.** It keeps a hit-rate property users already have, and it works
without git. Its one disagreement with git is harmless in direction. Every hash carries its
version prefix (`"c2:…"`), so a later change is a new prefix, not a silent reinterpretation.
`c2` is used only by the new layout. Changing today's `ContentHash` would move every existing
`k`.

Normalising line endings **removes a real outcome input from identity**. A test that reads a
fixture and compares bytes can pass on LF and fail on CRLF. So the observed line endings move
into the result entry: `env.eol` is `crlf` when any file the candidate hashed contained
`"\r\n"`. It is computed while hashing, so it costs nothing. Two Windows developers with
different `autocrlf` settings then share identity but not, under `exact`, results. A team that
adds `* text=auto eol=lf` to `.gitattributes` makes `eol` constant everywhere, and the
dimension disappears.

### Path separators

Every recorded path is project-relative with `/`. `Paths::relative()` already normalises
separators (`Paths.php:22`, `:104-107`), and `ContentKey` joins with `/` (`ContentKey.php:73`).
One risk is **unverified**: the root-prefix test in `Paths::relative()` compares strings
case-sensitively (`Paths.php:46-47`). If a Windows coverage driver reports `c:\…` while the
root is `C:\…`, the path falls to the `realpath()` retry (`:52-62`). Whether that always
rescues it has not been tested. If it does not, edges are silently dropped on Windows, which
is under-attribution. The measurement protocol includes a check.

### Case-insensitive filesystems

On macOS (by default) and on Windows, `file_get_contents('app/models/User.php')` succeeds for
`app/Models/User.php`. Coverage reports the path as it was opened, and a PSR-4 autoloader
builds that path from the class name as written. A recorded dependency can therefore carry a
case that does not exist in git. On Linux it hashes as missing (rule 2 fails, a miss). On
macOS it verifies. At publish time, each recorded path is resolved to its spelling in the git
index (`git ls-files`, exact match first, then case-folded). A path that is ambiguous (two
tracked paths differing only in case, which cannot both exist on those filesystems anyway) or
absent is not published.

The same wrong-case edge also affects today's local diff path on macOS, because a git diff
names `app/Models/User.php` while the graph knows `app/models/User.php`. That would be an
under-selection. **Unverified and unmeasured**, and outside this proposal's scope, but the
publish-time resolution is the same code that would fix it.

### Portable test ids

A test id is `Class::method`, plus `#<data set name>` for data-provider tests (PHPUnit's
`TestMethod::id()`). A data set keyed by a path (`__DIR__ . '/fixtures/a.json' => [...]`)
embeds the recorder's absolute root, so the id exists on no other machine. In filtered mode
this is worse than a miss. A replayed file is not handed to PHPUnit at all
(`RunPipeline.php:1385` returns the run list without it), so the consumer reports the
recorder's ids and never runs its own. In-process mode is safe: an id with no stored result
runs (`ReplayState.php:766-767`). So the publisher refuses a candidate whose test ids contain
its own absolute project root, in either separator form, or a drive-letter prefix. Messages
of skipped and incomplete results, which are replayed, have the root replaced with a
placeholder.

### Path length

Remote keys are digests (`…/tests/ab/<32 hex>/<32 hex>.json`), about 110 characters under the
remote root whatever the test file's name. That stays well inside Windows' 260-character
limit on the shared-folder backend.

## Measure first

The policy defaults must rest on data, and the data can be collected **today**, with v0.10.0
or with plain PHPUnit, on the machines the team already has.

### Protocol

Do this on at least one macOS, one Linux and one Windows machine.

1. **Pin the inputs.** The same commit on every machine, `composer install` from the lock,
   the same PHP minor. Record the facts that `meta` would record:
   `php -v`, `php -m`,
   `php -r "echo defined('INTL_ICU_VERSION') ? INTL_ICU_VERSION : 'no intl', PHP_EOL;"`
   (double quotes outside, so it runs the same in bash, PowerShell and `cmd`),
   `php -i` filtered for `precision`, `serialize_precision` and `date.timezone`, and
   `git config core.autocrlf`. Make the test environment file identical everywhere
   (`.env.testing` on Laravel). Clear framework caches.
2. **Run sequentially, twice, on each machine.** Sequential, because under Paratest the merged
   JUnit report's per-test `assertions` attribute is unreliable, and parallel order is one more
   variable. Compare status and message, never assertions or time. Two ways to run:
   - **plain PHPUnit:**
     `vendor/bin/phpunit --do-not-cache-result --log-junit out/<machine>-<php>-<n>.xml`.
     JUnit only distinguishes passed, failure, error and skipped.
   - **v0.10.0 (preferred):**
     `PHPUNIT_REPLAY_REMOTE_PUSH=off PHPUNIT_REPLAY_STATE_DIR=<an empty directory> vendor/bin/phpunit-replay record --fresh`,
     then copy `<that directory>/graph.json`. It records PHPUnit's full status (0–8,
     including notice, deprecation, risky and warning), which JUnit cannot express, and which
     is exactly where PHP minors differ.
3. **Vary one thing at a time.** Same OS, other PHP minor. Same PHP minor, other OS. On
   Windows, once with `core.autocrlf=true` and once with a `.gitattributes`
   `* text=auto eol=lf` checkout.
4. **Compare** with script 1. First each machine's run 1 against its run 2: that is the
   **noise floor** `F`, the tests that differ with nothing varied. Then across machines.
5. **Vendor,** with the bootstrap snippet and script 2 below: which packages a full run
   loads, and how many historical lock changes touched only packages outside that set.
6. **Windows edge check** (the `Paths.php:46-47` question): with v0.10.0 on Windows and on
   Linux, record once each and compare `edges` counts in the two `graph.json` files. A Windows
   count far below Linux's is the symptom.

### Script 1: compare two runs

Save as `compare-outcomes.php`. PHP, because every machine that runs the suite has it.

```php
<?php

// compare-outcomes.php — compare the per-test outcomes of two runs of the same suite.
//
//   php compare-outcomes.php A B [--strip=PATH]... [--branch=NAME] [--json]
//
// A and B are each either a PHPUnit `--log-junit` file or a phpunit-replay `graph.json`
// (both must be the same kind). `--strip` removes an absolute project root from messages
// (repeat it once per machine). `--branch` picks the baseline inside a graph.json; the
// default is the baseline holding the most results.

declare(strict_types=1);

const STATUS = [
    0 => 'passed', 1 => 'skipped', 2 => 'incomplete', 3 => 'notice', 4 => 'deprecation',
    5 => 'risky', 6 => 'warning', 7 => 'failure', 8 => 'error',
];
const RANK = ['passed' => 0, 'skipped' => 1, 'incomplete' => 2, 'notice' => 3, 'deprecation' => 4,
    'risky' => 5, 'warning' => 6, 'failure' => 7, 'error' => 8];

$files = [];
$strip = [];
$branch = null;
$json = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--strip=')) {
        $strip[] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--branch=')) {
        $branch = substr($arg, 9);
    } elseif ($arg === '--json') {
        $json = true;
    } else {
        $files[] = $arg;
    }
}

if (count($files) !== 2) {
    fwrite(STDERR, "usage: php compare-outcomes.php A B [--strip=PATH]... [--branch=NAME] [--json]\n");
    exit(2);
}

/** First meaningful line of a message, with roots, separators and line endings neutralised. */
function normalise(string $message, array $strip, string $testLine = ''): string
{
    $m = str_replace(["\r\n", "\r", '\\'], ["\n", "\n", '/'], $message);

    foreach ($strip as $root) {
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $m = str_ireplace($root, '', $m);
    }

    $lines = array_values(array_filter(array_map('trim', explode("\n", $m)), fn ($l) => $l !== ''));

    // JUnit prefixes a fault with "Class::test"; that line says nothing about the outcome.
    if ($lines !== [] && $testLine !== '' && $lines[0] === str_replace('\\', '/', $testLine)) {
        array_shift($lines);
    }

    return preg_replace('/\s+/', ' ', $lines[0] ?? '') ?? '';
}

/** @return array<string, array{status: string, message: string}> */
function load(string $path, array $strip, ?string $branch): array
{
    $raw = @file_get_contents($path);

    if ($raw === false) {
        fwrite(STDERR, "cannot read $path\n");
        exit(2);
    }

    $data = json_decode($raw, true);

    return is_array($data) ? fromGraph($data, $strip, $branch, $path) : fromJunit($raw, $strip, $path);
}

function fromGraph(array $data, array $strip, ?string $branch, string $path): array
{
    $baselines = $data['baselines'] ?? [];

    if ($branch === null) {
        $sizes = array_map(fn ($b) => count($b['results'] ?? []), $baselines);
        arsort($sizes);
        $branch = array_key_first($sizes);
    }

    if ($branch === null || ! isset($baselines[$branch]['results'])) {
        fwrite(STDERR, "$path: no baseline with results\n");
        exit(2);
    }

    $out = [];

    foreach ($baselines[$branch]['results'] as $id => $r) {
        $out[(string) $id] = [
            'status' => STATUS[$r['s'] ?? -1] ?? 'unknown',
            'message' => normalise((string) ($r['m'] ?? ''), $strip),
        ];
    }

    return $out;
}

function fromJunit(string $xml, array $strip, string $path): array
{
    $doc = new DOMDocument();

    if (! @$doc->loadXML($xml)) {
        fwrite(STDERR, "$path: neither JSON nor XML\n");
        exit(2);
    }

    $out = [];

    foreach ((new DOMXPath($doc))->query('//testcase') as $case) {
        $class = $case->getAttribute('class') ?: $case->getAttribute('classname')
            ?: basename(str_replace('\\', '/', $case->getAttribute('file')));
        $id = $class . '::' . $case->getAttribute('name');
        $status = 'passed';
        $message = '';

        foreach ($case->childNodes as $child) {
            if (in_array($child->nodeName, ['failure', 'error', 'skipped'], true)) {
                $status = $child->nodeName;
                $message = $child->textContent;
            }
        }

        $entry = ['status' => $status, 'message' => normalise($message, $strip, $id)];

        // A merged (paratest) report can repeat a case; keep the worse outcome.
        if (! isset($out[$id]) || RANK[$status] > RANK[$out[$id]['status']]) {
            $out[$id] = $entry;
        }
    }

    return $out;
}

$a = load($files[0], $strip, $branch);
$b = load($files[1], $strip, $branch);

$common = array_intersect_key($a, $b);
ksort($common);
$sameStatus = 0;
$sameOutcome = 0;
$differing = [];

foreach (array_keys($common) as $id) {
    $statusMatches = $a[$id]['status'] === $b[$id]['status'];
    $messageMatches = $a[$id]['message'] === $b[$id]['message'];
    $sameStatus += $statusMatches ? 1 : 0;

    if ($statusMatches && $messageMatches) {
        $sameOutcome++;

        continue;
    }

    $differing[] = [
        'test' => $id,
        'kind' => $statusMatches ? 'message' : 'status',
        'a' => $a[$id],
        'b' => $b[$id],
    ];
}

$n = count($common);
$pct = fn (int $x) => $n === 0 ? 0.0 : round(100 * $x / $n, 3);
$summary = [
    'a' => $files[0], 'b' => $files[1],
    'tests_a' => count($a), 'tests_b' => count($b),
    'only_in_a' => count($a) - $n, 'only_in_b' => count($b) - $n,
    'compared' => $n,
    'identical_outcome' => $sameOutcome, 'identical_outcome_pct' => $pct($sameOutcome),
    'identical_status' => $sameStatus, 'identical_status_pct' => $pct($sameStatus),
    'differing' => $differing,
];

if ($json) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

printf("A: %s (%d tests)\nB: %s (%d tests)\n", $files[0], count($a), $files[1], count($b));
printf("only in A: %d   only in B: %d   compared: %d\n", $summary['only_in_a'], $summary['only_in_b'], $n);
printf("identical outcome (status and message): %d (%.3f%%)\n", $sameOutcome, $pct($sameOutcome));
printf("identical status:                       %d (%.3f%%)\n", $sameStatus, $pct($sameStatus));

foreach ($differing as $d) {
    printf("\n[%s] %s\n  A: %-11s %s\n  B: %-11s %s\n", $d['kind'], $d['test'],
        $d['a']['status'], $d['a']['message'], $d['b']['status'], $d['b']['message']);
}
```

Example, a Linux run against a Windows run:

```
php compare-outcomes.php out/linux-8.4-1.xml out/windows-8.4-1.xml --strip=/home/ci/app --strip='C:\work\app'
```

It prints the number of tests compared, the percentage with an identical outcome (status and
normalised first message line) and with an identical status, the tests only one side ran
(which by itself flags non-portable test ids), and each differing test with both messages.
`--json` emits the same as JSON for aggregation. It was checked against synthetic JUnit and
`graph.json` inputs on PHP 8.4. It has not been run against a real three-OS matrix, which is
the point of the protocol.

### Script 2: how many lock changes would per-package vendor inputs absorb

First, find which packages a full run loads. Add a bootstrap that wraps the project's own:

```php
<?php
// tests/measure-bootstrap.php — run with: vendor/bin/phpunit --bootstrap tests/measure-bootstrap.php
require __DIR__ . '/../vendor/autoload.php'; // replace with the project's usual bootstrap

register_shutdown_function(static function (): void {
    $packages = [];

    foreach (get_included_files() as $file) {
        if (preg_match('#/vendor/([^/]+/[^/]+)/#', str_replace('\\', '/', $file), $m) === 1) {
            $packages[$m[1]] = true;
        }
    }

    ksort($packages);
    file_put_contents(sys_get_temp_dir() . '/loaded-packages-' . getmypid() . '.txt', implode("\n", array_keys($packages)) . "\n");
});
```

Merge the per-process files into one `loaded-packages.txt` (`sort -u` on macOS and Linux,
`Get-Content … | Sort-Object -Unique` on Windows). The per-process union is a superset of what
option C would record for any single test, so what follows is the *least* C would gain. Then,
from the project root:

```php
<?php

// lock-history.php — how many composer.lock changes touched only packages a run never loads.
//
//   php lock-history.php loaded-packages.txt [--since=6.months]
//
// Run from the project root. loaded-packages.txt: one "vendor/name" per line (the union of
// the files the bootstrap snippet wrote).

declare(strict_types=1);

$loaded = array_fill_keys(array_filter(array_map('trim', file($argv[1] ?? '') ?: [])), true);
$since = '6.months';

foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--since=')) {
        $since = substr($arg, 8);
    }
}

if ($loaded === []) {
    fwrite(STDERR, "usage: php lock-history.php loaded-packages.txt [--since=6.months]\n");
    exit(2);
}

/** @return array<string, string> package => version@reference */
function packagesAt(string $rev): array
{
    $json = shell_exec('git show ' . escapeshellarg($rev . ':composer.lock') . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    $lock = is_string($json) ? json_decode($json, true) : null;
    $out = [];

    foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $p) {
        $out[$p['name']] = ($p['version'] ?? '') . '@' . ($p['source']['reference'] ?? $p['dist']['reference'] ?? '');
    }

    return $out;
}

$commits = array_filter(explode("\n", (string) shell_exec(
    'git log --format=%H --since=' . escapeshellarg($since) . ' -- composer.lock'
)));
$total = 0;
$invisible = 0;
$metadataOnly = 0;

foreach ($commits as $sha) {
    $before = packagesAt($sha . '^');
    $after = packagesAt($sha);

    if ($before === [] || $after === []) {
        continue;
    }

    $changed = [];

    foreach (array_keys($before + $after) as $name) {
        if (($before[$name] ?? null) !== ($after[$name] ?? null)) {
            $changed[] = $name;
        }
    }

    if ($changed === []) {
        $metadataOnly++; // content-hash, time, urls: a normalised lock would not see it either

        continue;
    }

    $total++;
    $touchesLoaded = array_filter($changed, fn ($n) => isset($loaded[$n]));
    $invisible += $touchesLoaded === [] ? 1 : 0;
    printf("%s  %3d changed  %3d loaded  %s\n", substr($sha, 0, 10), count($changed), count($touchesLoaded),
        implode(' ', array_slice($touchesLoaded === [] ? $changed : $touchesLoaded, 0, 4)));
}

printf("\nmetadata-only lock changes: %d\nlock changes with a package change: %d\n", $metadataOnly, $total);
printf("of which touch no loaded package: %d (%.1f%%)\n", $invisible, $total === 0 ? 0 : 100 * $invisible / $total);
```

`metadata-only` is what A′ gains over A. `touch no loaded package` is the least C gains over
A′. Each line lists the loaded packages a change touched, which is how the tests that C
would still invalidate can be found. Checked against a synthetic four-commit history; not yet
run on a real project.

### Decision thresholds

Let `X` be the tests that differ between two environments in **status**, after removing the
noise floor `F` of both machines. Status, not message, because a message differing alone
(a float printed with more digits, a path) is informative but does not turn a pass into a
failure. Status differences that involve `4` (deprecation) and the other issue statuses count,
which is why the `graph.json` form is preferred.

| result, over two repetitions per machine | decision |
|---|---|
| `X = 0` across every OS pair, same PHP minor | `php_minor` is supportable as a team choice for laptops |
| `0 < X ≤ 0.1%` of compared tests, and every one explained by its message | put those test files in `environment_sensitive`, re-measure, and adopt `php_minor` only if `X = 0` among the rest |
| `X > 0.1%`, or any difference that is unexplained | stay on `exact` |
| `X = 0` across PHP minors too, among files not marked | `any` is supportable, as a local accelerator only |
| anything else across minors | no `any`. Expect this outcome for most real suites, since deprecations arrive with every minor |

The 0.1% figure is not a tolerated error rate. A deterministic difference under a relaxed
policy is a certain false green for that test. The figure only says when marking the
differing files by hand is practical, about nine files' worth at this suite's size. The
requirement after marking is zero. Measure again at every PHP minor upgrade and at every change
to the team's OS mix.

The same data also answers whether `eol` belongs in `exact`. If the `autocrlf=true` and `eol=lf`
Windows checkouts show `X = 0`, it can be dropped.

## Storage and collection

### Sizes

A candidate adds its dependency list to what an object holds today. At this project's size,
21,706 edges over 726 test files with the flag (`docs/reproducibility.md`) is about 30
dependencies per test file. At about 50 bytes of path and 35 bytes of versioned hash each,
that is roughly 2.5 KB per candidate. Without the flag it is 61,278 edges, about 84 per file,
so roughly 7 KB. Today's objects average about 3.5 KB: 5,099,022 bytes over 1,452 mirrored
objects (`docs/proposals/remote-layout.md`). So a candidate with one result entry is about
6 KB with the flag and 10.5 KB without. A universe is about 100 KB, shared by every candidate
of one recording.

| | today | this model, same number of writes | this model, if C absorbs a lock change |
|---|---|---|---|
| 6,257 objects/candidates | ~22 MB | ~38 MB (flag) / ~66 MB (no flag) | fewer candidates: a lock change that touches no loaded package adds entries, not candidates |
| per push after a lock change | ~800 new objects | ~800 new candidates under A′ | a result entry per executed file into existing candidates, or nothing when an entry for that environment exists |

The raw growth is real and is the main cost of the model. It is roughly halved by the flag,
and git's zlib packing will compress repeated paths well (the ratio is unmeasured). If size
ever matters, interning paths in a per-bucket table is a local change to the format.

### Lookups

Today a miss costs one `get` per shard for six months of shards, then a listing where the
backend supports one (`ObjectStore.php:168-187`, `SHARD_LOOKBACK` at `:53`). For a cold run of
726 files on HTTP, that is up to 4,356 requests. A bucket key has no month in it: one `get`
per test file.

### Collection

A bucket can never be *proved* unreachable: any tree can come back, a revert for instance. So
collection is by age, as `prune --remote` already does for shards (`PruneCommand.php:228`,
`:253-271`), but inside the bucket:

- drop result entries older than `--keep-months` (default 3, `PruneCommand.php:44`), then
  candidates with no entries left, then empty buckets, then universes no candidate
  references;
- cap each bucket at the newest `N` candidates (proposed 20). A test file that stays unchanged
  while its dependencies churn otherwise grows its bucket without bound.

**Writers trim as they merge**, applying the same two rules to the one bucket they are
rewriting. This matters for HTTP, which cannot list (`HttpRemoteCache.php:98-101`) and so can
never be swept. With trim-on-write its live buckets stay bounded. Abandoned buckets (old test
contents) and universes still accumulate there, as abandoned objects do today.

The six-month lookback disappears with the month in the key. The local mirror is collected
the same way, by age, since the addressability proof of `docs/proposals/remote-layout.md` §3
does not carry over: a bucket's key no longer contains the environment.

### Concurrency

| backend | merge | a lost update means |
|---|---|---|
| filesystem | read, merge, `AtomicFile` rename | a candidate or entry missing until the next publisher adds it: a miss |
| HTTP | read, merge, `PUT` (conditional `If-Match` where the server supports it, #34's open question 9) | the same |
| git | `adoptFetchedHistory()` (`GitRemoteCache.php:448`) re-writes this run's buffered keys over upstream after a rejected push, "ours wins" (`:23-37`). For a merge-only file that would drop the other writer's additions. **It must re-read upstream's bucket and write the union**, a hook keyed on the `v2/` prefix | nothing, once merged: the union is commutative |

Merging is safe because a bucket only ever gains candidates and entries, and both are sets.
Trimming is the one non-monotonic step. Two writers trimming differently can at worst drop
something one of them would have kept, which is a miss.

## Trust and provenance

Today any machine with write access publishes objects by default (`remote_push = 'objects'`,
`Config.php:51`). In the documented setup only CI publishes graphs. The first writer of a `k`
wins forever, because a key already mirrored or staged is never re-put (`ObjectStore.php:223`).
A flaky pass that lands first is therefore permanent for that content.

This model changes three things.

- **Provenance.** Each entry records `by` (`ci` or `dev`, and an opaque id: a CI run URL, or a
  digest of the committer email), `at`, `generator`, `run.workers` and `meta`. **These fields
  are self-asserted.** They serve diagnosis and policy, not security. The security boundary
  is, as today, who holds write credentials to the remote.
- **Disagreement blocks replay.** Entries accumulate, so a failing run on identical inputs no
  longer disappears behind an earlier pass. It becomes a second entry. A consumer that sees,
  among its accepted entries of valid candidates, any status that forces a re-run runs the
  test. This is cross-machine quarantine on exact inputs, which today's first-writer-wins
  cannot express. It only catches flakes someone executes, so a scheduled full `record` in CI
  adds value: it re-attests every candidate for its tree.
- **Consumer trust policy.** `accept_from`: `any` (default) or `ci`. The honest threat model is
  accidental rather than hostile. A developer who can write to the remote can also change the
  test. Accidents are what the guards target: flakes (disagreement), broken recorders (no
  driver, truncated passes, short dependency lists, see "What this model trusts"), and
  non-portable ids (see "Portable test ids"). Whether CI should accept developer entries is open
  question 5. The model works either way, and accepting them is what lets a PR job reuse
  what its author already ran.

Signing entries is possible and out of scope. Keys would have to be distributed to every
machine allowed to publish, which is the same set the remote's write ACL already names.

## Migration and coexistence

**Nothing is transformed, and nothing existing is invalidated.** The new layout lives under a
new prefix, `v2/<project-key>/`, beside `objects/` and `graph/`.

- **`SCHEMA_VERSION` does not move.** It sits in the structural bucket and so feeds every `k`.
  Bumping it discards every graph on every machine (`Fingerprint.php:50-53`,
  `docs/INTERNALS.md`). The new layout needs none of that: its keys are new keys. `Graph::SCHEMA`
  stays 1 as well.
- **`c2` hashes and the new vendor and scope inputs exist only inside `v2/`.** Today's
  `ContentHash` and `ContentKey` are untouched, so every existing `k` stays valid for as long
  as old readers exist.
- **Old clients** read only `objects/` and `graph/`, and old `prune --remote` deletes only under
  `objects/` (`PruneCommand.php:253-271`). They never see `v2/` and cannot damage it.
  `prune --remote --squash` on the git backend rewrites history for the whole tree, `v2/`
  included, and keeps its current content.
- **Dual write** for at least one minor release: a pass publishes to both layouts. New readers
  try `v2/` first and fall back to today's `k` lookup. That fallback carries the F1 fix, and it
  keeps its narrower rule (only edge-selected files) for as long as it exists.
- **Retirement:** once no supported client reads `objects/`, stop writing it and let
  `prune --remote` age it out. Graphs remain publishable as a source of `L(T)` for graph-less
  consumers (see "Under-attribution"). They are no longer needed to reach results.

Existing remotes need no action. The first dual-writing pass starts filling `v2/`, and
consumers begin to hit it as buckets appear.

## F2 and the layer concept

Layers exist because a result's validity is inferred from *where* it sits: a branch baseline
at a sha, trusted for exactly the files the diff from that sha leaves untouched
(`Graph.php:446-461`, `:486-499`). F2 is the case where the diff and the layer describe
different shas.

**A self-describing result has no layer.** It is valid when its inputs match the tree,
whoever recorded it, on whichever branch, at whichever sha. So F2 cannot occur for anything
served through verification. That covers all remote reads in this model, and local reads too
if the local store adopts the same form (the last step of the delivery order).

F2 survives exactly as long as the diff path serves layered local results. A narrow fix there
does not need this proposal: serve a result from a layer other than the one whose sha the diff
was taken from only when its stored `key` equals the `k` computed now. Results already carry
`key` (`Graph.php:968-970`). That is a suggestion for the separate F2 fix, not part of this
design.

## Relation to open PR #34 (`docs/proposals/graph-by-commit.md`)

#34 attacks the same symptom from the other side. Branches cold-start because the graph that
would open the door is missing, has no sha, or is not an ancestor. So #34 keeps more graphs, by
commit. This proposal removes the need for a graph to reach a result. The two overlap
unevenly:

| part of #34 | status under this proposal |
|---|---|
| guard: `push --graph` refuses a graph not finalized at `HEAD` (its failure mode 1) | **still useful, ship it**: a bug fix for as long as graphs are published, which remains true because graphs are the `L(T)` source for graph-less consumers |
| guard: detached `HEAD` refuses instead of publishing under `default_branch`; `--baseline-for` (failure mode 4) | **still useful, ship it**, same reason |
| `record` and `--allow-ci-baseline` (open question 7) | unaffected, still worth settling |
| step 1: the diff decides, not ancestry | **still useful** for the local diff path during coexistence, and cheap. It becomes moot when the local path adopts verification |
| failure mode 3: an unusable baseline disables remote objects | **fixed by construction**: lookup needs no baseline. INTERNALS' promise becomes true |
| step 2: commit-keyed graph history, pointers, first-parent walk, graph GC | **superseded.** Its purpose is to find the graph that opens the door to results at a branch's base. Results no longer need a door. A tip graph per integration branch is enough as an edge source, because union with any graph's edges is safe |
| open question 4: should a commit graph drop its results | **answered yes**, more strongly: the object is the unit of results, and a graph is edges |

So: merge #34's guards and step 1 as they are. Do not build step 2.

## Costs, stated plainly

| | |
|---|---|
| **remote size grows** | about +75% per object with the flag and +200% without, before compression, offset only where C absorbs lock changes. The largest cost of the model |
| **every pass hashes more** | today the diff path hashes changed files only. Verification hashes every recorded dependency of every candidate it checks, plus scope members: at this size roughly 1,800 source files and a few hundred scope members, once per pass. Unmeasured. A per-path `(mtime, size)` cache makes warm passes cost a `stat` per file |
| **a new structural input** (`composer.json` sections) | one fresh recording per project when it ships, as every structural key has cost |
| **precision lost in two scopes** | Blade ancestors and siblings with the flag on over-invalidate: misses, not false greens |
| **graph-less consumers without the flag get nothing** until they pull a graph | the price of not trusting the recorder's list on its own |
| **the git backend needs merge-on-replay** | a contained change to `adoptFetchedHistory()`, and the one place this model touches backend code |
| **unmeasured** | real hit rate across branches, verification cost, compression ratio, how much C absorbs, every environment question: the protocol above |

## Delivery order

Each step ships on its own, and none can produce a false green by itself.

1. **The F1 fix** (in flight): the diff path serves from the remote only files an edge
   selected.
2. **Measure** with the protocol above. No code: the owner's team runs scripts 1 and 2 on
   macOS, Linux and Windows.
3. **#34's push guards and step 1.** Independent bug fixes.
4. **Portability hygiene in today's layout:** refuse to publish objects with non-portable test
   ids, resolve dependency paths to their git-index spelling, check the Windows drive-letter
   question from the protocol. This only removes objects from publication.
5. **Write-only `v2/`:** publish candidates (`c2` hashes, scopes, A′ vendor, `composer.json`
   sections, universes, result entries with provenance) beside today's objects, with
   merge-on-replay on the git backend. Nothing reads them, so nothing can be served wrongly.
   `status` reports what a lookup *would* hit, and `verify` (a pure measurement since v0.10.0)
   can compare those would-be hits against real executions.
6. **Read `v2/` behind an opt-in** (`remote_lookup => 'objects'`), `exact` policy only, with the
   cover rule, and graph-less lookup only for candidates recorded with the flag. Default off
   until step 5's `verify` data shows zero disagreements.
7. **Environment policies** `php_minor` and `any`, and `environment_sensitive`, available only
   as the step 2 data allows, with CI documented as `exact`.
8. **Vendor option C**, if script 2 showed it worth the instrumentation.
9. **Retire `objects/`**: stop writing, let prune age it out. Graphs stay as edge sources.
10. **The local path adopts verification:** local results stored as self-describing candidates,
    layers retired, F2 gone rather than patched.

## Decisions (2026-09-30)

The owner answered the open questions below. Each answer is the one this proposal recommended.

| # | Question | Decision |
|---|---|---|
| 1 | Scope precision | Accept Blade and sibling over-invalidation to begin with. Refine only if `status` shows the misses. |
| 2 | What `exact` means | `{php minor, OS family, eol}`. Extensions, ICU and PHP patch are recorded, not matched, until the cross-OS measurement says otherwise. |
| 3 | Vendor | A′ (normalised lock) now; C after measurement. A package with no immutable reference makes the candidate **unpublishable**; it does not fall back to A′. |
| 4 | `composer.json` | `autoload`, `autoload-dev` and `extra` become a structural input. Cost: one fresh recording per project. |
| 5 | Trust | Developers publish objects. Laptops accept entries from anyone. **CI accepts only CI entries.** |
| 6 | Disagreement | A failing entry on identical inputs keeps blocking replay of those inputs. `status` reports them as flaky. They do not expire. |
| 7 | Hash definition | `c2`: normalised content, CRLF→LF, comment-insensitive, no git needed. |
| 8 | Graph-less consumers without the flag | Allowed after pulling a graph, which supplies the dependencies the cover rule needs. |
| 9 | Retention | 3 months and 20 candidates per bucket, configurable. |
| 10 | HTTP | Trim on write. Add a listing endpoint only if buckets are seen to grow unbounded. |
| 11 | The local path | Keep the diff path as the fast local mode. From 0.12 every served result is also validated by its stamp. Candidate verification is used for remote reads. Step 10 is not scheduled. |

Related, decided the same day for the local path, and shipping on its own as 0.12.0: every result is stamped with its content key and a digest of its non-edge inputs, and is served only when both still match. Objects are always publishable, because they are addressed by the content that actually ran. A graph is published only from a clean working tree.

## Open questions for the owner (answered above)

1. **Scope precision.** Accept the Blade and sibling over-invalidation to begin with, and
   refine only if the misses show up in `status`?
2. **What `exact` means.** Is `{php minor, OS family, eol}` right, or should `exact` also
   require the extension digest, the PHP patch version or the ICU version? Stricter means
   fewer hits between laptops and CI. The protocol's `meta` comparison can decide it.
3. **Vendor.** Confirm A′ now, C after measurement. For packages with no immutable reference,
   is falling back to A′ right, or should such candidates stay unpublished?
4. **`composer.json` as a structural input.** Accept one fresh recording per project for it?
   Which sections exactly: `autoload`, `autoload-dev`, `extra`, and anything else?
5. **Trust.** Should CI accept entries published by developers (`accept_from => any`), which
   lets a PR reuse what its author already ran? Or only CI entries? Should developers publish
   into the same remote at all?
6. **Disagreement.** A failing entry on identical inputs blocks replay of those inputs
   forever, since the inputs never change. Is that the right lifetime, or should it expire
   with `--keep-months` like everything else?
7. **Hash definition.** `c2` (normalised content, comment-insensitive, no git needed) or git
   blob ids (git's own normalisation, comment-sensitive)?
8. **Graph-less consumers without the flag.** Require a pulled graph as proposed, or refuse
   graph-less lookup entirely for such projects?
9. **Retention.** Keep 3 months of entries and 20 candidates per bucket, or derive them from
   the project's merge rate as #34's question 2 suggests?
10. **HTTP.** Is trim-on-write enough for HTTP remotes, which cannot be swept, or should the
    HTTP backend gain a listing endpoint?
11. **The local path.** Should step 10 happen at all, retiring the diff path's layers, or
    should the diff path stay as the fast local mode with verification only for remote reads?
