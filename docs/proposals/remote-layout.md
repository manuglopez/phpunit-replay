# Proposal: the environment belongs in the address, not in the path

**Status: accepted, implementation in progress.** This replaces an earlier version of this
document that proposed scoping the remote object tree by generation and environment
(`objects/<generation>/<environment>/<yyyy-mm>/<k>.json`). That design is **dropped**. What
follows closes the same correctness hole with a longer content key instead of a deeper tree,
and closes a growth problem the path design would have left untouched.

The earlier version is worth summarising only for why it was wrong, which is the first section.

## What the path proposal got wrong

It argued that scoping objects by environment was a correctness necessity, and its sharpest
claim was about coverage:

> A snapshot recorded under one format read back under another is not merely suspect — it is
> unreadable, and silently counts as missing coverage.

**That danger is real and it is local.** It has nothing to do with remote objects. A remote
object has exactly three keys, verified against a populated remote holding 1,452 of them:

```json
{"k": "<content key>", "file": "tests/Unit/…Test.php", "results": {"<test id>": {…}}}
```

No coverage. Coverage snapshots live at `<stateDir>/coverage/<k>.cov`
(`Record\CoverageSnapshots::path()`) and are never published — which is also why
`Report\CoverageMerger` warns `N replayed test file(s) had no readable coverage snapshot` when
a result is adopted from a remote rather than recorded here. The `coverage` fingerprint key
protects the *local* snapshot store, exactly as its own comment in `Cache\Fingerprint::compute()`
says: *"A recorded result is only replayable while its **stored** coverage snapshot is still
readable."*

So the proposal imported a local concern into a remote argument. With that removed, the
correctness hole is narrower than it claimed — and, more importantly, a path level is the wrong
instrument for it.

## The hole that is real

A result's address is built from the **structural** half of the fingerprint only:

```php
// src/Cache/ContentKey.php:46
$material = Fingerprint::canonicalStructural($fingerprint) . $testHash . implode('', $parts);
```

The environmental half is excluded, and nothing re-checks it when an object is adopted:

```php
// src/Console/Runner/RunPipeline.php, replayFromRemote()
$key = $contentKey->forTestFile($graph, $file);
$object = $key === null ? null : $objects->object($key);

if ($key === null || $object === null || $this->holdsARerun($object['results'])) {
```

Key, existence, and whether the result forces a re-run. Nothing else. Locally this cannot bite —
environmental drift discards the machine's own results (`reconcile()` → `clearResults()`) — but
that protection does not extend to what arrives from a remote.

What survives of the risk, key by key, is not uniform, and the difference decides the design.

## The growth problem the path design would not have touched

Everything read from a remote is mirrored at `<stateDir>/remote/cache/objects/<k>.json`, flat
(`Cache\Remote\ObjectStore.php:25`, `:304-307`). **Nothing ever collects it.** `keep-months`
exists only on `prune --remote`, which garbage-collects the *remote*.

Measured on one project's mirror after a single `composer.lock` change — the local `graph.json`'s
addressable keys against the mirror's contents:

| | |
|---|---|
| distinct content keys the local graph can address | 726 |
| objects in the mirror | 1,452 |
| reachable | 726 |
| **unreachable, i.e. one dead generation** | **726** |
| in the graph but never fetched | 0 |
| mirror size, apparent / allocated | 5,099,022 B / 8.3 MB |
| of which the dead half | 2.4 MB |

Exactly half dead after one dependency bump, on every developer machine, with no tooling. The
2.4 MB is small; the shape is not — every bump adds a generation and nothing removes one. The
3.2 MB gap between apparent and allocated is block overhead from 1,452 small files, which
matters to how the fix is built.

The framing that makes this obvious: **`prune --remote` already collects the remote by
reachability, and never applies that same rule to its own mirror.** This is an omission, not a
missing design.

## The design

### 1. The outcome-relevant environment goes into the content key

Add a `Fingerprint::canonicalResultEnvironment()` beside the existing `canonicalStructural()`,
and fold it into `ContentKey`'s material. It is deliberately **not** the environmental bucket
verbatim — it is the part of the environment that can change a test's *outcome*:

| key | in the address | why |
|---|---|---|
| `php` (`MAJOR.MINOR`) | **yes** | a result can legitimately differ across a PHP minor, which is a thing suites exist to catch |
| `os` (`PHP_OS_FAMILY`) | **yes** | path separators, locale, filesystem behaviour |
| `driver` (`pcov` \| `xdebug`) | **no** | it changes which lines are *reported*, not whether an assertion passed. This repository's own scripts alternate them (`composer test` is pcov, `composer test:xdebug`), and a developer doing the same would halve their hit rate daily in exchange for no correctness at all |
| `coverage` (`CoverageFormat::id()`) | **no** | the object carries no snapshot, per the first section |

There is no digest to size. `canonicalStructural()` is not a hash — it is canonical JSON that
*contains* hashes — so the environment enters the material the same way, as a whole canonical
JSON object. JSON objects are self-delimiting, so the concatenation addresses exactly one
(project, environment) pair with the same collision properties the structural half already had,
and needs no separator.

**Two notions of "compatible" is normally how correctness bugs get in**, and the earlier version
of this document said so. The split survives that objection because it is not arbitrary: the
line is exactly *"can this change the outcome"* versus *"can this change the coverage"*, and the
two halves already serve different consumers — results and edges. Naming the subset in a method
whose name says which question it answers is what keeps it honest.

### 2. `php` and `os` stay in the environmental bucket as well

This is addition, not relocation, and the redundancy is load-bearing. A graph adopted from
another environment still `structuralMatches()`, so its **edges** are inherited — correctly,
because edges are a property of the tree. But its **results** carry keys this machine will now
never compute, so they are dead weight inside `baselines[<branch>]['results']`.
`environmentalDrift()` → `clearResults()` is what removes them. Take `php`/`os` out of the
bucket and the adopted corpse stays.

### 3. Mirror collection becomes a certainty rather than a heuristic

With the environment in the address, *every object a machine can address is by construction from
its own environment*. So:

- an object recorded elsewhere has an address this machine will never compute — **provably**
  unreachable, not merely absent from today's graph;
- and when the environment does change, every key changes at once, so the whole mirror dies in
  one step instead of decaying indistinguishably.

That is the whole argument for putting the environment in the key rather than the path: it turns
eviction from an estimate into a proof. A path level would have given the same correctness and
none of this.

The one thing keeping the old environment's objects buys is a hit on switching back. With
`php` + `os` only, switching back means rolling back a PHP upgrade or changing OS — rare enough
that paying a refetch is right. Had `driver` been in the key, switching back would be every
`composer test:xdebug`, and keeping both sets would have mattered.

### 4. Eviction is a truncate, not an unlink

The mirror file does two jobs, and each reads a different part of the file:

| role | what it inspects | with a 0-byte file |
|---|---|---|
| "already published" marker (`ObjectStore.php:214`) | `is_file($this->mirrorPath($k))` | **true** → still skips re-publishing |
| read cache (`ObjectStore::object()`) | `decodeObject()` of the contents | **null** → falls through to the remote and refills it |

Verified in code, not assumed: `Support\AtomicFile::read()` returns `null` only when the file is
absent or unreadable — on an empty file it returns `''` — and `decodeObject('')` fails at
`Json::decodeArray()`. So truncation frees the blocks, keeps the marker, and **requires no change
to either path**. Collection is purely additive.

Two levels, the second behind a flag:

| | effect | cost if wrong |
|---|---|---|
| `prune` (default) | truncates unaddressable objects | one refetch from the remote |
| `prune --forget-published` | unlinks, reclaiming the inode | a redundant `put`, idempotent because objects are content-addressed and immutable |

The flag exists because on a client with `remote_push: objects` an unlink causes re-publication —
noise on a shared git repository, never data loss. Losing a marker cannot lose an object: the
marker is an optimisation, and the invariant it rests on (*mirror present ⟹ object durable in the
remote*) is only ever established by a successful read or by `confirmPublished()` after a landed
push.

### 5. `<stateDir>/coverage/<k>.cov` needs a different rule, not the same one

An earlier draft of this document said snapshots are keyed by content key too. **They are not**,
and the difference matters. `Record\CoverageSnapshots.php:68` keys them by
`Cache\ContentHash::of()` of the **test file itself**, and the class docblock gives the reason: a
content key additionally needs a `Graph` for the file's dependencies, which does not exist inside
the PHPUnit child process in filtered/wrapper mode. A replaying pass can still find the snapshot
because it re-hashes a file that — for the replay to be valid at all — has not changed.

Two consequences, and the first is why this section is short.

**Nothing in section 1 orphans a snapshot.** Snapshot keys are content hashes of files, so moving
every *content key* leaves every snapshot addressable exactly as before. There is no one-time
local coverage gap on upgrade.

**But the collection rule is its own.** A snapshot is live iff its `k` equals
`ContentHash::of()` of a test file the graph currently knows, at that file's *current on-disk
content*. Everything else is stale for one of two permanent reasons: the file changed, so its
hash changed and the old key can never be computed again; or the file is gone. That is cheaper
than the mirror's rule rather than harder — no key set to reconstruct from baselines, just the
known test files hashed as they are now.

`PruneCommand` does not mention snapshots once. The directory does not exist on any state
directory inspected here, so it is not growing today — but nothing would collect it if it did.

### 6. `status` can finally say what the state is

`mirror: 1452 objects · 726 reachable · 2.4 MB reclaimable`. This is the legibility the path
proposal claimed for the remote — *"answerable only by reconstructing every baseline's keys"* —
delivered locally, with no migration, by the same set comparison the collector runs.

It also answers that proposal's open question 3, "a machine cannot tell *nobody has recorded in
my environment* from *the remote is empty*", from the other side: with the environment in the
address, an empty result for a populated remote **is** the answer "nobody in your environment",
and `status` can say so.

## Costs, stated plainly

| | |
|---|---|
| **cross-environment sharing ends** | intended. But far cheaper than under the path design: a Linux/8.4 CI runner and a Linux/8.4 laptop still share, whichever coverage driver each uses. Only a different OS or a different PHP minor separates them |
| **every remote object is invalidated once** | the address changes. v0.8.0 has just invalidated every graph for an unrelated reason, so doing this now costs one recording that is already being paid |
| **hit-rate loss is unmeasured** | inherited from the earlier version and still true. It could be measured by recording the same suite under two PHP minors and counting addressable overlap |
| **no format change, no path change** | objects keep their three keys and their `objects/<yyyy-mm>/<k>.json` location. Writes stay conflict-free immutable files |

## Migration

Objects are content-addressed, so there is nothing to transform — the addresses simply change.
Drop `objects/` and let the baseline job republish, exactly as before. Graphs are unaffected in
shape; their `results` are cleared by the environmental drift that the new keys accompany.

## What is dropped, and why it is safe to drop

The path design's second motivation was making dead-generation purge a directory delete instead
of a full object scan. Weighed against what was actually measured, this does not carry a
migration:

- reclaiming space on the git backend is **already implemented** —
  `prune --remote --squash` force-pushes an orphan commit and clients re-clone on their next
  `begin()` (`GitRemoteCache.php:242-286`);
- the scan it would have replaced walks ~1,500 files in a local working tree;
- and `prune --remote` is not, today, run by anything at all — which is a workflow line, not a
  layout.

A generation level can be added later without moving any object, if a remote ever grows to where
the scan hurts. The reverse — unwinding a path migration — is not true, which is the reason to
prefer the key now.

## Open questions

1. **Does `driver` belong out for good?** The claim is that a coverage driver cannot change an
   assertion's outcome. Xdebug also changes error handling and timing, so a timing-sensitive test
   could in principle flip. Unmeasured, and deliberately traded away for hit rate.
2. **Should collection run automatically?** A silent cache eviction is defensible, and
   `GitRemoteCache` already has automatic maintenance. Starting explicit (`prune` only, reported
   by `status`) is the conservative order; the reverse is hard to undo.
3. **The HTTP backend still cannot list.** Irrelevant to mirror collection, which is entirely
   local, and to remote collection, which already requires the filesystem or git backend. Noted
   only so it is not rediscovered.
