# Proposal: a branch's baseline comes from its integration branch's history, not its tip

**Status: proposal, not implemented.** Nothing below exists in `src/` yet. Line references are
to `main` at `0a96cef` (v0.10.0). The measurements come from one consuming project, a Laravel
suite of about 10,900 tests that shares its cache through a dedicated git repository with
`baseline_branches => ['develop', 'master']`, taken on 2026-09-29/30. That project is pinned to
v0.8.0. Where this matters it is called out.

The proposal has two steps, and the first one may be enough on its own. **Step 1** stops
requiring a baseline to be an ancestor of `HEAD` and lets the diff decide. **Step 2** publishes a
bounded history of integration-branch graphs keyed by commit, so a branch can find the graph
recorded at its own base. Branch names remain, but as pointers.

## What is shared today, and what is not

A result is already shared by everyone who can compute its address. An object is
`objects/<yyyy-mm>/<k>.json`, and `k` is a hash of the structural fingerprint, the
outcome-relevant environment, the test file's content and the content of each of its recorded
dependencies (`Cache\ContentKey::compute()`, SPEC §4.3). No branch, machine or commit appears in
that material. A laptop, a PR job and the baseline job that reach the same content reach the same
object. **Sharing between developers already happens here, at the level of results, and this
proposal leaves it alone.**

The graph plays a different role. It holds the edges (test file → source files) and the sha they
were recorded at. From the diff between that sha and `HEAD`, it decides **what runs**. It is
also what makes objects reachable at all: `ContentKey::forTestFile()` needs
`Graph::dependenciesOf()` to compute a key. A machine without a usable graph can address nothing,
so it runs everything.

Three facts about graphs frame everything below:

- **Only CI publishes them.** A developer's pass records a local baseline and publishes objects
  only. `push --graph` runs from a baseline job with the write credential, after
  `run --allow-ci-baseline` (sharing-the-cache.md, the dedicated-repository section).
- **So developer commits never have published graphs.** Two developers' feature commits are never
  near each other, and the design must not depend on them being near. What every branch shares
  with every other branch is **its base on the integration branch**: the commit it was cut from,
  or a later integration commit it merged in.
- **Only the tip of each integration branch is kept.** `graph/<project-key>/<branch>.json`
  (`ObjectStore::graphKey()`, `ObjectStore.php:89-92`) is overwritten by every publish. The
  graph that described a feature branch's base disappears when the next merge lands.

So the useful "nearest published graph" for a feature branch is, in practice, **the graph at
its merge-base with the integration branch**. Today that graph stops existing as soon as the
integration branch moves.

## What a consumer actually hit

On 2026-09-30 the consumer's cache repository held three graphs:

| `graph/p-871dcd44167bf4e5/…` | last written | `baselines.<own>.sha` | `complete` | results |
|---|---|---|---|---|
| `develop.json` | 2026-09-18 | **`null`** | absent | 10,685 |
| `ci-fix-tia-baseline.json` | 2026-09-29 | `c7a1ce9` | `true` | 10,883 |
| `chore-phpunit-replay-0.5.json` | 2026-09-09 | — | — | — |

Four failure modes explain why every PR cold-started. They are listed in the order a pass meets
them.

**1. A published graph can have no sha, and then it is invisible to selection.** `develop.json`
was published by `push --graph`, which publishes whatever local graph exists. It checks neither
whether the branch's baseline was finalized nor whether it has a sha (`PushCommand.php:69-90`).
The pass before it was a CI `record`, and `record` hardcodes `allowCiBaseline: false`
(`RecordCommand.php:54`), so `BaselineWriter::commit()` never called `finalizeBaseline()`
(`BaselineWriter.php:37-46`). `BaselineResolver::shaFor()` reads the branch's own sha, finds
`null` and drops the candidate (`BaselineResolver.php:108-124`). `pullStartingGraph()` still
adopts the graph, because it reconciles fingerprints and never looks at the sha
(`RunPipeline.php:423-440`). The docs still point users into this trap: SPEC §12 recommends
`record --fresh && push`, INTERNALS "GitHub Actions" shows `record --fresh -p` then
`push --graph`, and the README's command table calls `record` "what CI runs after a merge". The
shipped example, `.github/workflows/examples/tia-baseline.yml`, is correct and uses
`run --allow-ci-baseline`.

**2. Even with a sha, only the tip survives, and the tip stops being an ancestor.** The consumer
merges into `develop` about 106 times a month (first-parent commits since 2026-09-01). The branch
that published `c7a1ce9` was cut from `develop` at `85e3c7d`. Within the next 48 minutes
`develop` gained 30 commits touching 46 files. The branch itself changed **1** file. A
`develop` graph at `85e3c7d` would have left that branch 1 file away. The tip graph instead
fails `git merge-base --is-ancestor`, and the candidate is dropped
(`BaselineResolver.php:58`). A second, independent gate applies the same check to whatever sha
is used (`ChangedFiles.php:30`).

**3. A dropped baseline means a full record, and remote objects play no part in it.** When
`ChangedFiles::since()` returns `null`, `runReplay()` warns
`baseline … is not an ancestor of HEAD`, discards the graph and calls `runRecord()`
(`RunPipeline.php:1044-1050`). `replayFromRemote()` has one call site, in the replay path
(`RunPipeline.php:1074`). So a pass that finds edges and objects for nearly every test file
still runs all ~10,900 tests. This contradicts INTERNALS "Pipeline changes", which says an
unreachable baseline is still used "as a source of `objects` by key (edges still useful) —
record fresh but replay-remote by `k` still applies". The code does not do this. For comparison,
the one pass that did find its own graph reported `0 executed / 10883 replayed (from remote)`
and saved 46m13s.

**4. A run's branch name comes from the checkout, and the checkout is not always the branch.** A
scheduled GitHub workflow runs from the default branch (`master` in this project) and has to
check `develop` out explicitly. Whether it lands on a named branch or on a detached `HEAD`
depends on how the ref is given. The two cases fail differently:

- detached, `run`: `currentBranch()` is `null`, so `persist` is false and the branch becomes the
  literal `'HEAD'` (`RunPipeline.php:267-268`). No sha is finalized and no graph is pushed
  (`RunPipeline.php:1453`). The run does nothing useful, and it says so only in debug output.
- detached, `push --graph`: the branch falls back to `default_branch`
  (`PushCommand.php:69`). The graph is published **under the default branch's name**, with
  whatever baselines the local graph happens to hold. It does not warn.

Keying graphs by commit does not fix mode 1. That needs a guard in `push`. It is included below
because the new layout cannot tolerate it either.

## Why ancestry is required today, and whether it has to be

SPEC §7.1 step 1 states the rule without giving a reason: *"`git merge-base --is-ancestor <sha>
HEAD`; if it fails → the baseline is unreachable → fresh record"*. `ChangedFiles` is a port of
Pest's class of the same name (its header says so), and the check arrived with the port. D-039
repeats the rule for `baseline_branches` ("among ancestors") and gives no reason for it either.
So the right question is what, in this codebase, depends on the check. Each consumer of the sha
was checked:

| consumer of the baseline sha | what it does | does it need ancestry? |
|---|---|---|
| `ChangedFiles::diffSinceSha()` (`:119`) | `git diff --name-only --no-renames <sha>..HEAD` | **no**: two-dot `diff` compares two trees and is symmetric. It lists every tracked path that differs, in either direction |
| `ChangedFiles::filterContentUnchanged()` | `git show <sha>:<path>` against the working tree | **no**: it needs the commit object locally, not a relationship |
| `ChangedFiles::workingTreeChanges()` / `LastRunTree` | `git status` and the dirty-file snapshot, keyed by branch | **no**: neither reads the sha's history |
| `BaselineResolver::distance()` (`:129`) | the count of the same diff | **no** |
| selection rules (§7.2) | edges of files in the diff → affected tests | **no**: correct for any pair of trees whose diff is complete, which a tree diff always is |
| test files known to the graph but absent on disk | dropped from selection; `pruneMissingTestFiles()` | **no**: this already happens on an ancestor after a deletion |
| test files on disk the graph does not know | `unknown` → executed | **no** |
| quarantine (`flaky.json`) | flip detection on an unchanged `k` | **no**: local, keyed by test id and `k` |
| coverage snapshots | `<stateDir>/coverage/<ContentHash(test file)>.cov` | **no**: keyed by content |

The argument that makes selection correct is the same for both directions. **A test whose own
file and whose recorded dependencies are all absent from the diff sees the same content at the
graph's sha as it does now.** Edges were recorded at the graph's sha. If a change adds a
dependency, it does so by changing a file the test already depends on, and that file is in the
diff. Nothing in that argument says which of the two commits came first. A graph recorded on
`develop` after the branch was cut simply shows `develop`'s later changes as differences, and
their tests run.

**Ancestry is therefore not what makes selection correct.** It stands in for two other
properties, and each can be checked directly:

- **"the sha is reachable"** means the commit object exists locally. That is
  `git cat-file -e <sha>^{commit}`. When the object is missing, `git diff` already fails and
  `since()` already returns `null` (`ChangedFiles.php:38-41`).
- **"the diff is small"** is what `baseline_branches` selection already measures.

Relaxing the rule costs something, and the costs are not correctness costs:

| cost | measured | notes |
|---|---|---|
| **distance inflation**: the integration branch's changes since the base count as differences | 47 files vs 1 in the case above | tests hit by `develop`'s newer changes run on the PR. Many of them may replay from the remote anyway: the branch holds the base's content for those files, and `develop`'s baseline job published objects for that content when it ran at the base. This only helps where the tip graph's edges for a test match the base's. Unmeasured |
| **a structural change on the integration branch after the base** | `composer.lock` changed on `develop` after `develop.json` was written (`331b837`) | the tip graph fails `structuralDrift()` for the branch whatever its ancestry. Only a graph at or before the base can help. That is step 2's job |
| **the sha is missing on a shallow clone** | — | same as today: fall back and record |

## The design

### Step 1: the diff decides, not ancestry

No layout change. The changes are local to two classes:

- `ChangedFiles::since()`: replace the `isAncestor()` gate with "the commit object exists". Keep
  `null` for a missing object or a failed git.
- `BaselineResolver::resolve()`: keep any candidate whose `distance()` can be computed, and
  still choose the fewest files away. Prefer an ancestor on a tie. Print whether the chosen
  baseline is an ancestor
  (`baseline develop@94f13bb (nearest, 47 files away, not an ancestor)`). A user who sees a
  number like that should know why it is large.
- The warning at `RunPipeline.php:1046` and its twin in `ReplayState` (`:801`) now fire only when
  the object is missing, and say so.

A side effect helps developers directly. **A developer who rebases a branch today loses the
branch's own local baseline**, because the old sha is no longer an ancestor. With step 1 it is
kept, and it is usually the nearest candidate by far.

Step 1 also removes most of the reason to fix failure mode 3 separately. Once non-ancestors are
accepted, a baseline is dropped only when its commit is missing, and a missing commit is a much
narrower case to cold-start on. Whether `runRecord()` should also consult remote objects is
listed as an open question.

### Step 2: a bounded history of integration graphs, keyed by commit

Step 1 leaves two cases open: a structural change on the integration branch after the base, and
distance inflation on a fast-moving integration branch. Both are fixed by keeping the graph that
was recorded **at** the base.

#### Layout

```
graph/<project-key>/<branch>.json            # legacy tip, unchanged (dual-write period, then read-only)
graph/<project-key>/commits/<sha>.json       # one graph per published integration commit (40-hex, full sha)
graph/<project-key>/refs/<slug(branch)>.json # pointer: this branch's recent published commits, newest first
```

`commits/` is flat. A retained history is tens of files per integration branch, not thousands,
so a two-character shard would only complicate collection. `slug()` is the existing
`ObjectStore::slug()` (`:523`).

#### Pointer format

```json
{
  "layout": 2,
  "branch": "develop",
  "graphs": [
    {"sha": "94f13bbc0…", "at": "2026-09-30T01:41:07Z", "structural": "xxh128…", "generator": "manuglopez/phpunit-replay 0.11.0"},
    {"sha": "85e3c7df6…", "at": "2026-09-30T00:53:12Z", "structural": "xxh128…", "generator": "manuglopez/phpunit-replay 0.11.0"}
  ]
}
```

`structural` is a digest of `Fingerprint::canonicalStructural()`. A reader can then skip a graph
that is structurally unusable **without downloading it**, which matters at 4.8 MB a graph (see
Costs). The pointer is the index. Nothing ever lists the remote to resolve a baseline, and that
is what makes this layout work on the HTTP backend, which cannot list
(`HttpRemoteCache::keys()` sets `listing not supported`).

#### What a commit graph contains

It is a **flattened** graph. Today a published branch graph is the publisher's whole
`graph.json`. That includes every branch baseline it holds, and each of them is a delta over the
default branch (`Graph::mergedResults()`, `:446`). A commit graph holds exactly one baseline:
`results(<branch>)` already merged through the fallback chain, `sha` equal to the commit,
`complete: true`, plus edges, files, tables, `not_cacheable` and the fingerprint. On the read
side it is inserted as a single layer under a synthetic name (`@<sha7>`), and the existing
nearest-layer mechanism is used (`Graph::setNearestBranch()`). Because the name is not a git
branch, `prune --branches` drops it locally the next time it runs.

#### Publish flow (CI-only writers)

`push --graph` gains the new layout and three guards:

1. **Refuse a graph that was not finalized at `HEAD`.** `ownRecordedSha(<branch>) === HEAD` and
   `isBaselineComplete(<branch>)` must both hold, or the command exits non-zero. This closes
   failure mode 1 in both layouts.
2. **An explicit `--baseline-for=<branch>` (and `PHPUNIT_REPLAY_BASELINE_FOR`) names the
   pointer**, and the same option on `run` supplies the branch that `persist` and
   `finalizeBaseline()` use on a detached `HEAD`. It is checked, not trusted: `HEAD` must be
   reachable from `origin/<branch>` (`git merge-base --is-ancestor HEAD origin/<branch>`), or
   the command refuses. Without the option, the checked-out branch is used as now, and a detached
   `HEAD` **refuses** instead of falling back to `default_branch`. This closes failure mode 4.
3. **Write order: the graph, then the pointer.** On the filesystem backend both are
   `AtomicFile` renames, so a reader can never see a pointer entry whose graph is missing. On
   the git backend both go in one commit and one push. On HTTP, a reader that meets a pointer
   entry before the graph exists gets a 404, skips that entry and tries the next one.

`--allow-ci-baseline` keeps its meaning. It remains the only way a CI pass finalizes a sha, and
guard 1 makes it a prerequisite for `push --graph` in CI instead of something a user must
remember to pass. `record` should accept the flag too (open question 7).

Only integration branches publish. PR jobs run on synthetic merge commits
(`refs/pull/N/merge`) that are an ancestor of nothing anyone else will check out. A graph keyed
by one of those commits is garbage from the moment it is written.

#### Lookup algorithm

For a pass at `HEAD`, before any graph is downloaded:

1. **Own local baseline first**, from `graph.json`, exactly as today. It is local-only and never
   published, and with step 1 it survives a rebase.
2. For each `b` in the candidates (`baseline_branches`, else `[default_branch]`):
   1. `GET graph/<key>/refs/<slug(b)>.json`: one small read.
   2. Drop entries whose `structural` digest differs from this machine's.
   3. `mb = git merge-base HEAD origin/<b>`. This is already the latest integration commit the
      branch contains, whether it is the commit the branch was cut from or a later one it merged
      in. If it fails (no ref, shallow clone), go to 2.5.
   4. `git rev-list --first-parent --max-count=<D> <mb>` once, walking the integration branch's
      own line back from `mb`, and intersect the result with the pointer's shas. The first hit
      is the newest published graph at or before the base. It is usually `mb` itself, unless
      that commit's baseline job was cancelled or failed.
   5. The pointer's newest entry is also a candidate under step 1's rules, even though it is not
      an ancestor.
3. Compute `distance()` for the survivors, at most two per candidate branch. Pick the fewest
   files, and break ties by candidate order and then by ancestry.
4. `GET graph/<key>/commits/<sha>.json` for the winner **only**, reconcile it and use it.
5. If nothing survived, fall back to the legacy `graph/<key>/<b>.json` chain, and after that
   record fresh, as today.

Cost, against today's resolution:

| | today | step 2 |
|---|---|---|
| remote reads to choose a baseline | one **whole graph** per candidate, just to read its sha (`BaselineResolver::shaFor()` → `ObjectStore::graphOf()`) | one pointer per candidate, then one graph |
| bytes, two candidates, HTTP | ~2 × 4.8 MB | ~2 × 1 KB + 4.8 MB |
| git processes | 1 `merge-base --is-ancestor` + 1 `diff` per candidate | 1 `merge-base` + 1 `rev-list` per candidate + ≤2 `diff` |
| bound | number of candidates | number of candidates × `D` revisions in `rev-list` output (proposed `D = 500`) |

The git calls use the default 5-second timeout (`Git.php:16`). A `rev-list` of 500 revisions
takes milliseconds. Any failure (a shallow clone, a missing `origin/<b>`, a timeout) removes
that candidate and nothing else, following the rule that a remote is an accelerator and never
a dependency.

#### Interactions

| existing concept | under this proposal |
|---|---|
| `baseline_branches` | still the candidate list. It now names **which pointers to read**, and still breaks ties. The ancestry filter it describes becomes a preference |
| `default_branch` | still the single-candidate shorthand and the final layer of `Graph::fallbackChain()`. A flattened commit graph makes that layer redundant for the adopted baseline, but it is harmless and stays for local deltas |
| `--allow-ci-baseline` | unchanged, and required by `push --graph` guard 1 on CI |
| `remote_push` | unchanged. `push --graph` is still not gated by it (sharing-the-cache.md: "`all` is needed nowhere") |
| quarantine | unchanged. `flaky.json` is local, never published, and keyed by test id and `k`. A flip against a CI-recorded result quarantines locally, as it does today with a branch graph |
| coverage merge | unchanged mechanism. Adopting more work from a remote graph means fewer local recordings, so `CoverageMerger` will more often warn "`N replayed test file(s) had no readable coverage snapshot`" for `--coverage-php` users. The warning is true, not a regression |
| mirror collection (`prune`) | `Graph::addressableKeys()` reads the local graph, which now holds one flattened remote layer instead of a copy of the publisher's deltas, so it is unchanged |

## Relation to `docs/proposals/remote-layout.md`

**That design has already landed** (v0.9.0, D-044). A result's address carries `php` and `os`,
so a result recorded under a different PHP minor or OS cannot be addressed. Graphs are safe
across environments by the same design: edges are inherited because they are a property of the
tree, and results are cleared by `environmentalDrift()` → `clearResults()` (remote-layout §2).
So nothing here waits on it.

Two points make it more urgent, not less:

- **Wider sharing widens the window for clients that predate it.** The consumer measured here
  runs v0.8.0, where a remote object is still addressable across environments. Today it cold-starts
  on almost every PR, so in practice it reads few remote objects. Once branches find their base
  graph, a pre-0.9 client will replay far more remote objects, and each one is a potential
  cross-environment adoption. The layout-2 pointer should therefore record the generator, and
  readers should ignore graphs published by a generator older than 0.9.0. The generator field
  cannot be trusted before 0.8.1 (CHANGELOG 0.8.1: it read `0.1.0-dev` in every graph), and
  every graph on the consumer's remote carries that value. The `layout: 2` marker is what
  separates old graphs from new ones.
- **Objects are retained by graph reference.** `prune --remote` keeps an old-shard object when
  any `graph/**` file references it (`PruneCommand.php:232-266`). A history of N graphs per
  integration branch pins the union of their keys. Because the environment is in the address,
  that union is still one environment's objects per publisher environment.

## Garbage collection

What to keep, for each pointer:

- **always the newest entry**;
- **the last N entries** (proposed `N = 20`, a little under a week at the consumer's rate);
- **anything younger than K days** (proposed `K = 14`), capped at `2N` so a burst of merges
  cannot grow the pointer without bound.

`prune --remote` gains a graph sweep that runs before the existing object sweep, so objects that
only the dropped graphs referenced are released in the same run:

1. For each `refs/*.json`, trim the entries to the policy above and rewrite it.
2. Delete each `commits/<sha>.json` that no pointer references.
3. **Legacy tips:** delete `graph/<key>/<branch>.json` for any branch the project's origin no
   longer has (`git ls-remote --heads origin`). This runs from a checkout, as the GC workflow
   already does. Today no graph is ever deleted, so
   `chore-phpunit-replay-0.5.json` (last written 2026-09-09) keeps its objects alive forever.
4. The existing object sweep, unchanged in rule: shards older than `--keep-months`, except keys
   referenced by any surviving `graph/**` file. `collectReferencedKeys()` already recurses into
   every key under `graph/`, and pointer files contain no `"k"`.

New options: `--keep-graphs=N` and `--keep-graph-days=K`, next to `--keep-months`. On the git
backend, removed graphs stay in history until `--squash`, as objects already do.

## Concurrency

| situation | outcome |
|---|---|
| two publishers, **different** shas | disjoint `commits/` paths, no conflict. The pointer is the contended file (below) |
| two publishers, the **same** sha (re-run, cancelled-and-restarted job) | the graphs are equivalent but **not byte-identical**: timings differ, and coverage attribution has a documented residual nondeterminism (`docs/reproducibility.md`). Either one is a valid baseline for that sha. **First writer wins**: `has(commits/<sha>.json)` → skip, so the pointer does not churn |
| pointer, same branch | CI concurrency groups normally serialize a branch's baseline job. When they do not: the git backend replays this run's buffer over upstream on a rejected push (`adoptFetchedHistory()`), so the **last push wins**, and the losing entry's graph becomes unreferenced until GC. On HTTP it is a read-modify-write with the same lost-update outcome. A lost entry is a cache miss, never a wrong baseline |
| pointer, different branches | different files, no contention |

Atomicity for each backend: **filesystem**: `AtomicFile` temp-and-rename, graph before pointer.
**git**: one commit carries both, and `end()` pushes or fails as a unit; the publish-marker rule
(`ObjectStore::confirmPublished()`) extends to graphs, so `push` reports success only after
`end()`. **HTTP**: two PUTs in order, and readers tolerate the gap as described above.

## Migration and compatibility

- **Old readers** only ever request `graph/<key>/<branch>.json`. They never see `commits/` or
  `refs/`, so the new layout is invisible to them.
- **Dual write** for at least one minor release: `push --graph` writes the commit graph, the
  pointer, **and** the legacy tip (flattened, which old readers accept because it is a valid
  `Graph::decode()` input). Old and new clients share one remote throughout.
- **New readers** try pointers first and fall back to legacy tips (lookup step 5).
- **Old `prune --remote`** keeps every object that commit graphs reference, because it recurses
  into all of `graph/`, and it never deletes graphs. Running an old GC job against a new remote
  is therefore safe, only less effective.
- **Version marker:** `"layout": 2` in each pointer. There is no remote-wide marker file, so
  there is nothing for two writers to disagree about. `Graph::SCHEMA` stays at 1: a commit graph
  is an ordinary schema-1 graph with one baseline.

## Costs, stated plainly

| | |
|---|---|
| **remote size** | one graph is 4.83 MB raw and 339 KB gzipped at this suite size. About 2.2 MB of it is edges and file tables; the rest is results, which duplicate objects. With `N = 20` per integration branch that is ~97 MB in the tip tree. The consumer's whole remote tree is 32.2 MB today (5,343 objects at 18.9 MB, three graphs at 13.4 MB), and GitHub reports ~4.7 MB packed |
| **every fresh git mirror checks out every retained graph** | `GitRemoteCache` clones `--depth 1` (INTERNALS "GitRemoteCache — automatic maintenance"), and ephemeral CI runners clone on every job. That is ~20 × 339 KB of transfer before git's own delta compression across similar graphs, which is unmeasured. This is the strongest argument for keeping `N` small, and for open question 4 |
| **step 1 selects more on a fast integration branch** | 47 files instead of 1 in the measured case. That is bounded by the integration branch's churn, not by the suite, and it replaces a full record |
| **two new options on `push`/`run`, two on `prune`** | `--baseline-for`, `--keep-graphs`, `--keep-graph-days`, plus the refusal paths in `push --graph` |
| **unmeasured** | how often step 1 alone already reaches the right graph, and how much of step 1's inflation replays from remote objects |

## Alternatives considered

**Step 1 alone, as the whole answer.** This is a serious candidate and possibly the right end
state. It is small, needs no layout change, and fixes failure mode 2 in the common case. It does
not fix a structural change on the integration branch after the base, and its inflation grows
with the integration branch's churn. The recommendation below ships it first and makes step 2
depend on what it measures.

**Branch pointers only (`refs/<branch> → <sha>`, graph still at `graph/<branch>.json`).** This
fixes naming (failure mode 4) and makes resolution cheap. It keeps no history, so a branch whose
base has scrolled off the tip is in exactly today's position.

**Merge-base with the default branch only (fetch `commits/<merge-base>.json`, nothing else).**
This is the core of step 2, but on its own it is too brittle. The merge-base is a publish point
only when that exact commit's baseline job succeeded. The consumer's job uses
`cancel-in-progress: true`, so a rapid second merge cancels the first, and a single flaky test
fails the job and skips publishing. Walking the first-parent line back to the nearest published
graph is what makes the lookup survive both.

**Publish graphs for every commit, including PR and developer commits.** Rejected. Developer
commits are never near each other. PR merge refs are synthetic commits nobody else has as an
ancestor. Every such graph is written, never read, and pins objects until GC.

**Content-address the graph itself (`graph/<hash-of-graph>.json`).** Lookup still needs a
commit → hash mapping, so this is the pointer design plus one more level of indirection. Its
benefit is deduplication, and a graph's content changes on every run because of timings, so it
would almost never deduplicate. Applied to the edges alone, it might (open question 4).

**Nearest graph across all branches and developers.** Rejected by construction, for the reason
in the first section: only integration commits have graphs, and what branches share is their
integration base. Searching wider costs lookups and buys nothing.

## Recommendation

1. **Fix the guards now, in any layout:** `push --graph` refuses a non-finalized graph and a
   detached `HEAD` without `--baseline-for`. `record` accepts `--allow-ci-baseline`, or SPEC
   §12, INTERNALS and the README stop recommending it for CI. These are bug fixes, and they
   decide whether the consumer's `develop` baseline is usable at all.
2. **Ship step 1**, with the `not an ancestor` marker on the baseline line. Measure the distance
   distribution and the remote-hit share on the consumer's PRs for two weeks.
3. **Ship step 2 if** that measurement shows structural crossings or inflation large enough to
   matter. Its layout is additive, so waiting costs nothing but the benefit.

## Open questions

1. **Is step 1 enough?** The answer is a measurement: step 1's distance and remote-hit share
   against the counterfactual merge-base distance, over the PRs of one busy integration branch.
2. **Defaults for `N`, `K` and `D`.** The ones proposed here are derived from one project's merge
   rate (≈106 a month). A project that merges ten times a day needs a different `N` than one
   that merges weekly. Should `N` be derived from `K` instead?
3. **Should a reader fetch a missing commit?** On a shallow clone, `git fetch --depth=1 origin
   <sha>` would make a pointer's sha diffable at the cost of a network round-trip. Today's answer
   (fall back, record) is simpler.
4. **Should a commit graph drop its results?** Every result is also an object under the same
   `k`. A graph of edges, files and tables would be ~2.2 MB instead of 4.8 MB, and unaffected
   tests would replay from objects. That means one object read per unaffected test file on a
   cold mirror, instead of zero. This is worth measuring before step 2 fixes the format.
5. **Is `HEAD` reachable from `origin/<branch>` the right check for `--baseline-for`?** It
   rejects a scheduled run that checked out the wrong ref. It also rejects a legitimate
   workflow that records before pushing. Should it be a warning instead?
6. **Squash- and rebase-merging integration flows.** If `develop` is squashed into `master`,
   `develop` graphs are never ancestors of `master` commits. Step 1 tolerates that. Step 2's
   first-parent walk finds nothing, and step 1's tip candidate is all that remains. Is that
   acceptable?
7. **`record` and `--allow-ci-baseline`.** Add the flag (the smaller change), or deprecate
   `record` for CI in favour of `run --fresh --allow-ci-baseline`, which already works?
8. **Should a baseline that cannot be used still unlock remote objects?** INTERNALS already
   promises this ("record fresh but replay-remote by `k` still applies"), and the code does not
   do it (failure mode 3). After step 1 the case is rare: a baseline is dropped only when its
   commit is missing, and without the commit the edges are unverifiable. The likelier fix is to
   correct INTERNALS.
9. **HTTP pointer lost updates.** Is a conditional PUT (`If-Match` on an ETag) worth supporting
   for backends that offer it, or is "a lost entry is a cache miss" good enough?
