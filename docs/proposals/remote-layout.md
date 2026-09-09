# Proposal: environment-scoped remote layout

**Status: proposed, not implemented.** Nothing in the package behaves this way today. This
document exists so the decisions below get made before code, because they change the remote's
on-disk layout and that is cheap to change exactly once.

It closes one correctness hole and one operational one, and it is deliberately smaller than the
"two-part remote object" idea it partly replaces (see [Relationship to the two-part
object](#relationship-to-the-two-part-object)).

## The two problems

### 1. A result can be adopted across environments that cannot compare (correctness)

A result's address is built from the **structural** half of the fingerprint only:

```php
// src/Cache/ContentKey.php:46
$material = Fingerprint::canonicalStructural($fingerprint) . $testHash . implode('', $parts);
```

The **environmental** half — `php` (MAJOR.MINOR), `driver`, `os`, `coverage` — is deliberately
excluded (`Cache/Fingerprint.php`, `compute()`). And nothing re-checks it when an object is
adopted from a remote:

```php
// src/Console/Runner/RunPipeline.php, replayFromRemote()
$key = $contentKey->forTestFile($graph, $file);
$object = $key === null ? null : $objects->object($key);

if ($key === null || $object === null || $this->holdsARerun($object['results'])) {
```

Key, existence, and whether the result forces a re-run. Nothing else. So a result recorded under
PHP 8.2 with Xdebug is findable — and replayable — by a machine on PHP 8.4 with pcov.

Locally this cannot bite: environmental drift discards the machine's own cached results
(`reconcile()` → `clearResults()`). That protection does not extend to what is adopted from a
remote.

The `coverage` key makes this sharper than "a result might differ". `CoverageFormat::id()` is in
the environmental bucket because php-code-coverage changes both the `--coverage-php`
serialization *and* the shape of the coverage data between majors. A snapshot recorded under one
format read back under another is not merely suspect — it is unreadable, and silently counts as
missing coverage.

### 2. Dead generations accumulate and are expensive to identify (operational)

Measured on a real cache repository serving one project of ~9,000 tests: after a single
`composer.lock` change, the remote held **1,452 objects of which 726 were reachable**. The
structural fingerprint changed, so every content key changed, and the previous generation became
unaddressable but not deleted. Repository size went from 7 MB to 14 MB.

Every dependency bump adds a full generation. `prune --remote --keep-months=N` does not reclaim
them until the *month shard* ages out, and it reclaims by walking every object and testing it
against every baseline's referenced keys:

```php
// src/Console/Commands/PruneCommand.php:188
foreach ($cache->keys('graph/') as $graphKey) {
```

## Today's layout

```php
// src/Cache/Remote/ObjectStore.php:82
return 'graph/' . $projectKey . '/' . self::slug($branch) . '.json';
// src/Cache/Remote/ObjectStore.php:87
return 'objects/' . $shard . '/' . $k . '.json';     // $shard = yyyy-mm
```

## The proposed layout

```
graph/<projectKey>/<branch>.json                       ← unchanged
objects/<generation>/<environment>/<yyyy-mm>/<k>.json  ← two new levels
```

Both new levels are short hashes a machine computes locally from state it already has. No
listing, no extra round trip: a client knows its own generation and environment before it asks
for anything.

The effect on problem 1 is the point: **a machine only ever reads inside its own environment's
subtree.** The mismatch stops being something to validate and becomes something unreachable.
There is no check to forget and no policy to choose.

### Why the graph does not move

The graph holds edges and results. Edges are a property of the tree, not of the machine; results
are not. The package already draws exactly that line — `structuralDrift()` makes a graph
unusable, `environmentalDrift()` keeps the edges and discards the results. So the branch baseline
stays project- and branch-scoped, and a consumer whose environment differs inherits the edges
(valuable, portable) while `reconcile()` throws away the results (not portable). Scoping objects
by environment does not make the graph environment-safe; the existing reconcile already does,
by discarding.

This layout is a directory-level reflection of a rule the code already enforces.

## What goes in each level

### `<environment>`

The environmental bucket, verbatim, as it already exists:

| key | source | why it must separate |
|---|---|---|
| `php` | `PHP_MAJOR_VERSION.PHP_MINOR_VERSION` | a test's result can legitimately differ across a PHP minor — which is a thing suites exist to catch |
| `driver` | `pcov` \| `xdebug` | the drivers do not report identical executed lines |
| `os` | `PHP_OS_FAMILY` | path separators, locale, filesystem behaviour |
| `coverage` | `CoverageFormat::id()` | a snapshot from another major is unreadable, not just suspect |

`hash('xxh128', canonicalEnvironmental($fingerprint))`, truncated. A `canonicalEnvironmental()`
alongside the existing `canonicalStructural()` is the whole of the new hashing code.

### `<generation>`

Here there is a real decision, and it is the main thing this document exists to settle. The
structural bucket today is:

| key | changes when | in the path? |
|---|---|---|
| `schema` | the package's cache schema version bumps | **yes** — a new schema genuinely is a new generation, for everyone at once |
| `edges_exclude_ignored` | never (unconditionally `true`) | irrelevant either way |
| `composer_lock` | dependencies change | **yes** — this is the case that motivated the proposal |
| `phpunit_xml`, `phpunit_xml_dist` | the suite's configuration changes | **yes** — it can change which files are even sources |
| `replay_config` | `phpunit-replay.php` changes | **questionable — see below** |
| `static_declaration_edges`, `analysis_rules` | only present when the flag is on | **yes** — the flag changes what an edge means |

**The `replay_config` question.** Adding one `watch` glob changes `replay_config`, therefore the
structural fingerprint, therefore every content key — and under this proposal it would also
create a whole new generation directory. But a `watch` pattern has nothing to do with whether a
recorded result is still comparable; it only affects *selection*. Today's coarseness is already
paid for in key churn; this proposal would additionally pay for it in directory churn.

Two options, and this is a decision, not an oversight:

1. **Use the whole structural bucket.** Generation == "graphs that are mutually usable", which is
   exactly what `structuralMatches()` already means. Simple, consistent, and one extra generation
   per config edit.
2. **Use a narrower subset** (`schema` + `composer_lock` + `phpunit_xml*` + the flag keys) so a
   config edit does not fork a generation. Cheaper operationally, but it introduces a *second*
   notion of "compatible" alongside `structuralMatches()`, and two notions of compatibility is
   how correctness bugs get in.

**Recommendation: option 1.** The churn is real but bounded and visible, and one definition of
compatibility is worth more than a few directories. A narrower key can be introduced later
without moving anything if the churn turns out to hurt; the reverse is not true.

## What purge becomes

A dead generation stops being a scan and becomes a directory.

**The safety rule does not change.** "Dead" is not "old" — it is *"no live branch baseline
references it"*, exactly what `prune --remote` already enforces. A developer on an older branch
with an older `composer.lock` resolves to the older generation, and purging it takes their cache
away. What changes is only the cost of evaluating that rule: compare generation directory names
against the fingerprints the live baselines carry, instead of walking every object and testing it
against a set of referenced keys.

This also makes the state legible for the first time. Today "how much of this remote is dead?" is
answerable only by reconstructing every baseline's keys. After, it is a directory listing — which
means `status` and `prune --remote --dry-run` can *tell* you, and a machine sitting alone in an
otherwise-empty generation could be told so, instead of silently finding nothing.

## Costs, stated plainly

| | |
|---|---|
| **cross-environment sharing ends** | today a Linux/pcov CI runner and a macOS/Xdebug laptop *do* share results, unsafely. After, never. That is the intent, but a heterogeneous team's hit rate drops, by an amount this document does not measure. It is also the strongest argument for the recommended "only CI writes" posture: with one producing environment, nothing is lost |
| **more directories** | the same objects, more tree entries. Git handles it, and deleting a subtree is one tree change rather than N blob deletions |
| **generation churn** | one new generation per structural change, including config edits under the recommended option 1 |
| **migration** | the path layout changes. No object *format* change, which is the significant saving over the two-part object design — no read-modify-write, no variant collections, writes stay conflict-free immutable files |

## Migration

Objects are content-addressed and immutable, so there is no data to transform — only a location
to change. Three options:

1. **Re-seed.** Drop `objects/` and let the baseline job republish. Costs one full recording. The
   graph is unaffected. Simplest, and cheapest while the number of populated remotes is small.
2. **Dual read for one release.** Look in the new path, fall back to the old flat path, never
   write the old one. Then re-seed at leisure. Costs a compatibility branch in `ObjectStore` and
   the discipline to remove it.
3. **Move in place.** A one-off command that reads each object, recomputes its destination, and
   rewrites the tree. Needs listing (see the open questions) and cannot determine the environment
   an existing object was recorded under — **the information is not stored anywhere**, which is
   the very hole this proposal closes. So option 3 is impossible for existing objects, not merely
   expensive.

**Recommendation: option 1**, and do it before there is a second populated remote.

## Relationship to the two-part object

An earlier design proposed moving the dependency list out of the address and into the object
body, so adoption validates the producer's dependency list against the consumer's tree. That
design and this one are complementary, not alternatives:

| | closes | cost |
|---|---|---|
| **this proposal** | the **correctness** hole: environment | low — paths only |
| two-part object | the **completeness** hole: a dependency the producer knew about and the consumer's graph never recorded is currently never checked | high — object format |

This proposal also disposes of the two-part design's unresolved problem. With a stable address,
one address could have several legitimate bodies (different environments, different converging
dependency lists), forcing a choice between last-write-wins, variant collections inside an
object, or a second address level. **This proposal *is* that second level**, placed where it
belongs — so the collision never arises.

Do this first. The two-part object becomes optional rather than necessary.

## Open questions

These are unresolved. Naming them is the point of writing this down.

1. **The HTTP backend cannot list.** `prune --remote` already requires the filesystem or git
   backend for exactly this reason. Purge-by-generation needs to enumerate generation directories,
   so on HTTP it gains nothing and the age-based path remains the only option. Either the design
   accepts a backend-dependent purge story, or `RemoteCache` grows a listing capability that S3
   can satisfy (it can — prefix listing is native) and a bare WebDAV endpoint may not.

2. **Two GC axes may fight.** The `yyyy-mm` shard exists so age-based collection is cheap. With
   generations as the unit of purge, is the month shard still carrying weight, or does it just
   fragment a generation across shards and make "delete this generation" N deletes again? Likely
   answer: keep it, because a *live* generation still accumulates objects over months and needs
   age-based trimming within itself. Not verified.

3. **Discovery.** A machine computes its own generation and environment, so it never needs a
   listing to *read*. But it therefore cannot tell "nobody has ever recorded in my environment"
   from "the remote is empty" — the two look identical, and the first is worth a warning while
   the second is not. Needs a cheap probe or an index object, and an index object reintroduces a
   mutable shared document.

4. **Hit-rate loss is unmeasured.** The claim that ending cross-environment sharing costs a
   heterogeneous team something is stated but not quantified anywhere. It could be measured by
   recording the same suite under two environments and counting addressable overlap.

5. **Truncation length.** Both new levels are truncated hashes. `ProjectKey` uses 16 hex
   characters. A collision between two generations would be a correctness failure, not a cache
   miss, so the length should be argued rather than copied.

6. **`trackedHash()` returns null for untracked files.** An untracked `phpunit-replay.php` or
   `phpunit.xml` therefore produces a *different structural fingerprint* on the machine that has
   it — which under this proposal means a different generation directory, silently. This is
   already a documented hazard for addresses; the proposal makes it visible as an orphan
   directory, which is arguably an improvement, but it is not a fix.
