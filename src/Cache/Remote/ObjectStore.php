<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache\Remote;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;

/**
 * The two things the package actually stores remotely, on top of any {@see RemoteCache}
 * (SPEC.md §9, docs/INTERNALS.md "Phase 3 contracts — distribution"):
 *
 *  - `graph/<project-key>/<branch>.json` — a whole branch baseline, body = {@see Graph::encode()}.
 *  - `objects/<yyyy-mm>/<k>.json` — the results of ONE test file under ONE content key,
 *    body = `{"k": …, "file": <project-relative test file>, "results": {testId: result}}`.
 *
 * An object is addressed by content (`k`), and its body grows: it holds the results recorded
 * under each non-edge input digest it has been published with (the newest at the top level,
 * older ones under `variants`, identical outcomes under `aliases`), merged on publish and
 * bounded ({@see self::VARIANTS}). So the month in the key is only a shard (the month the
 * object was last written to), never part of its identity. Lookups try the current month and
 * the previous five by name — six cheap `get`s, no listing — and only fall back to a full
 * `keys()` scan on backends that support one.
 *
 * Everything read is mirrored under `<stateDir>/remote/cache/objects/<k>.json`, flat: `k`
 * is already unique, so the local mirror needs no shard and a second lookup for the same
 * key costs one `is_file()`. Whether a digest is already published is asked of the remote's
 * own copy, not of that mirror ({@see self::putObject()}). Graphs are mutable and are never
 * mirrored.
 *
 * Bug fix: that mirror file doubles as {@see self::putObject()}'s "already published"
 * marker, so it must mean the object is durably in the remote — never merely that `put()`
 * returned true, which for the git backend only means "staged in a local commit" ({@see
 * \Manuglopez\Replay\Cache\Remote\GitRemoteCache}'s `end()` is what actually pushes). Writing
 * the marker any earlier turned one transient push failure into permanent, silent data loss:
 * `putObject()`'s own skip check would see the marker and never retry that object again, on
 * any later run. See {@see self::confirmPublished()}, which now owns writing it.
 *
 * Nothing ever collected that mirror until {@see self::collectMirror()}: every generation a
 * dependency bump invalidates left its objects behind forever, one flat file each. It is
 * collected by ADDRESSABILITY rather than age, same as `prune --remote` already collects the
 * remote itself — a key the local graph's own baselines no longer reference (across every
 * branch, {@see Graph::addressableKeys()}) is provably unreachable, not merely unseen today
 * (docs/proposals/remote-layout.md). Collection truncates rather than unlinks by default,
 * precisely so it never has to touch the marker invariant above — see
 * {@see self::collectMirror()}'s own docblock.
 *
 * An object written from this release on also carries `n`, the non-edge input digest its
 * top-level results were recorded under (`Select\NonEdgeInputs`), beside `k` in the body: its
 * path and `k` are unchanged, and a reader that predates it ignores the field (and reads the
 * top-level results, as it always did). A consumer whose own digest for the test file equals
 * `n`, a variant's or an alias's knows every input the rule chain can see is the one those
 * results ran on. An object without any digest proves only what `k` covers.
 *
 * @phpstan-import-type TestResultArray from Graph
 * @phpstan-type RemoteObject array{k: string, file: string, n: ?string, at: int, results: array<string, TestResultArray>, variants: array<string, array{at: int, results: array<string, TestResultArray>}>, aliases: array<string, array{of: string, at: int}>}
 */
final class ObjectStore
{
    /** @var int months before the current one an object lookup probes by name */
    private const SHARD_LOOKBACK = 5;

    /**
     * @var int digests one object names, newest first ({@see self::putObject()}): variants
     *      with their own results and aliases of one, together
     */
    public const VARIANTS = 20;

    /** @var array<string, true> keys {@see self::object()} already asked the remote for again this pass */
    private array $refetched = [];

    /** @var list<string>|null memoised `keys('objects/')` for the fallback scan */
    private ?array $listing = null;

    /** @var array<string, ?string> memoised graph bodies by branch (one download per run) */
    private array $graphBodies = [];

    /**
     * Bug fix: objects {@see self::putObject()} handed to `put()` this session, keyed by
     * `k`, not yet confirmed durable — see {@see self::confirmPublished()}, which is what
     * finally writes their local "already published" marker, and only once the remote's
     * `end()` has actually confirmed the write landed.
     *
     * @var array<string, string>
     */
    private array $pending = [];

    public function __construct(
        private readonly RemoteCache $remote,
        private readonly string $stateDir,
        private readonly string $projectKey,
    ) {
    }

    public function remote(): RemoteCache
    {
        return $this->remote;
    }

    /** False for {@see NullRemoteCache}: nothing configured, every call is a no-op. */
    public function enabled(): bool
    {
        return $this->remote->name() !== 'null';
    }

    public static function graphKey(string $projectKey, string $branch): string
    {
        return 'graph/' . $projectKey . '/' . self::slug($branch) . '.json';
    }

    public static function objectKey(string $shard, string $k): string
    {
        return 'objects/' . $shard . '/' . $k . '.json';
    }

    /**
     * The raw `graph.json` body a branch baseline was published with, or null. Memoised:
     * the pipeline and {@see \Manuglopez\Replay\Change\BaselineResolver} both ask for the
     * same branches, and a graph is the one big object in the store.
     */
    public function graph(string $branch): ?string
    {
        if (array_key_exists($branch, $this->graphBodies)) {
            return $this->graphBodies[$branch];
        }

        $key = self::graphKey($this->projectKey, $branch);
        $body = $this->remote->get($key);

        $this->debug(($body === null ? 'miss' : 'hit') . ' ' . $key);

        return $this->graphBodies[$branch] = ($body === null || $body === '') ? null : $body;
    }

    /** {@see self::graph()}, decoded against `$projectRoot`; null when absent or unreadable. */
    public function graphOf(string $branch, string $projectRoot): ?Graph
    {
        $body = $this->graph($branch);

        return $body === null ? null : Graph::decode($body, $projectRoot);
    }

    public function putGraph(string $branch, string $body): bool
    {
        $key = self::graphKey($this->projectKey, $branch);
        $ok = $this->remote->put($key, $body);

        $this->debug(($ok ? 'put' : 'put-failed') . ' ' . $key);

        if (! $ok) {
            $this->warnLastError();

            return false;
        }

        $this->graphBodies[$branch] = $body;

        return true;
    }

    /**
     * The results recorded for one content key, wherever its shard is.
     *
     * @return RemoteObject|null
     */
    public function object(string $k, ?string $digest = null): ?array
    {
        if ($k === '') {
            return null;
        }

        $local = $this->mirrorPath($k);
        $cached = AtomicFile::read($local);
        $mirrored = $cached !== null ? self::decodeObject($cached, $k) : null;

        if ($mirrored !== null) {
            // Objects grow a variant per digest, so a mirrored copy without the one asked for
            // may just be older than the remote's: asked again once per key and pass.
            if ($digest === null || self::holds($mirrored, $digest) || isset($this->refetched[$k])) {
                $this->debug('hit (local mirror) objects/*/' . $k . '.json');

                return $mirrored;
            }

            $this->refetched[$k] = true;
        }

        return $this->fetch($k) ?? $mirrored;
    }

    /**
     * Whether an object holds results recorded under `$digest` (its top-level `n` or a
     * variant).
     *
     * @param RemoteObject $object
     */
    public static function holds(array $object, string $digest): bool
    {
        return $object['n'] === $digest || isset($object['variants'][$digest]) || isset($object['aliases'][$digest]);
    }

    /**
     * The results an object proves for a test file whose current non-edge digest is
     * `$digest`: the ones recorded under that very digest, top-level or variant. An object
     * written before digests existed proves only its content key, and serves only a file
     * selected for reasons the key covers (`Select\Selection::coveredByContentKey()`).
     *
     * @param RemoteObject $object
     * @return array<string, TestResultArray>|null
     */
    public static function resultsFor(array $object, ?string $digest, bool $coveredByContentKey): ?array
    {
        if ($object['n'] === null && $object['variants'] === [] && $object['aliases'] === []) {
            return $coveredByContentKey ? $object['results'] : null;
        }

        if ($digest === null) {
            return null;
        }

        $digest = $object['aliases'][$digest]['of'] ?? $digest;

        if ($object['n'] === $digest) {
            return $object['results'];
        }

        return $object['variants'][$digest]['results'] ?? null;
    }

    /**
     * Two bodies of the same object merged: every digest either names, newest first, bounded
     * the way {@see self::putObject()} bounds one. What the git backend replays over upstream
     * after a rejected push (`GitRemoteCache`), so a concurrent publisher's variant survives.
     * Null when either body is not an object under `$k`.
     */
    public static function mergeBodies(string $upstream, string $ours, string $k): ?string
    {
        $a = self::decodeObject($upstream, $k);
        $b = self::decodeObject($ours, $k);

        if ($a === null || $b === null) {
            return null;
        }

        if ($b['n'] === null) {
            return $upstream;
        }

        return Json::encode(self::normalise([...self::entriesOf($a), ...self::entriesOf($b)], $k, $b['file']));
    }

    /** @return RemoteObject|null the remote's copy, mirrored locally when found */
    private function fetch(string $k): ?array
    {
        $local = $this->mirrorPath($k);

        foreach ($this->shards() as $shard) {
            $body = $this->remote->get(self::objectKey($shard, $k));

            if ($body === null) {
                continue;
            }

            $decoded = self::decodeObject($body, $k);

            if ($decoded === null) {
                continue;
            }

            $this->debug('hit ' . self::objectKey($shard, $k));
            AtomicFile::write($local, $body);

            return $decoded;
        }

        $key = $this->findInListing($k);

        if ($key !== null) {
            $body = $this->remote->get($key);

            if ($body !== null) {
                $decoded = self::decodeObject($body, $k);

                if ($decoded !== null) {
                    $this->debug('hit ' . $key);
                    AtomicFile::write($local, $body);

                    return $decoded;
                }
            }
        }

        $this->debug('miss objects/*/' . $k . '.json');

        return null;
    }

    /**
     * Publishes the results of one test file under its content key and non-edge digest.
     *
     * One object per `k`, holding a variant per digest its results were recorded under: the
     * newest at the top level (`n`, `results`, which is all a reader that predates variants
     * reads) and up to {@see self::VARIANTS} − 1 older ones under `variants`, merged on
     * publish. So a digest another machine published first does not stop this one's from
     * being served. Whether the digest is already there is asked of the remote's own copy in
     * the shard this writes to (one `get`), or of what this pass staged, never of the local
     * mirror alone: a variant this machine published and a concurrent writer later dropped is
     * then published again. The merge is with that copy, or with the mirrored one when the
     * current shard has none (an older shard's object); the git backend merges again with
     * upstream when its push is rejected. What remains possible: two writers of the same `k`
     * racing on a file or HTTP remote, where the last `put` wins and the other's variant is
     * lost until it publishes again — a miss for it, never a result served for another digest.
     *
     * Results identical in outcome to a variant already there (same test ids, statuses,
     * messages and assertion counts: a digest-only change, such as a translation file every
     * test watches) are recorded as an alias of that variant, one short entry, instead of a
     * copy. The number of writes stays what it always was — one per test file a pass
     * executed — and the object's size grows by the alias only. An object published without a
     * digest (by a caller that has none) is skipped whenever the key is already known, as
     * before digests existed.
     *
     * @param array<string, TestResultArray> $results
     * @param string|null $digest the non-edge input digest every one of `$results` was
     *        recorded under
     */
    public function putObject(string $k, string $testFileRel, array $results, ?string $digest = null): bool
    {
        if ($k === '' || $results === []) {
            return false;
        }

        $key = self::objectKey(self::currentShard(), $k);

        if ($digest === null) {
            // As before digests: the key known anywhere, the mirror marker included, is enough.
            if (isset($this->pending[$k]) || is_file($this->mirrorPath($k))) {
                $this->debug('skip (already published) objects/*/' . $k . '.json');

                return false;
            }

            $known = null;
        } else {
            $staged = isset($this->pending[$k]) ? self::decodeObject($this->pending[$k], $k) : null;
            $upstreamBody = $staged === null ? $this->remote->get($key) : null;
            $upstream = $upstreamBody !== null ? self::decodeObject($upstreamBody, $k) : null;
            $authoritative = $staged ?? $upstream;

            if ($authoritative !== null && self::holds($authoritative, $digest)) {
                $this->debug('skip (already published) ' . $key);

                return false;
            }

            $known = $authoritative ?? self::decodeObject((string) AtomicFile::read($this->mirrorPath($k)), $k);
        }

        $body = Json::encode(self::withVariant($known, $k, $testFileRel, $results, $digest, time()));

        if ($body === null) {
            return false;
        }
        $ok = $this->remote->put($key, $body);

        $this->debug(($ok ? 'put' : 'put-failed') . ' ' . $key);

        if (! $ok) {
            $this->warnLastError();

            return false;
        }

        // Bug fix: NOT `AtomicFile::write($this->mirrorPath($k), $body)` here anymore.
        // `put()` returning true means "accepted" — durable immediately for the file/http
        // backends, but for the git backend only staged in a local commit `end()` has not
        // necessarily pushed yet. Buffered instead; the caller confirms via
        // {@see self::confirmPublished()} once `end()` says the push actually landed.
        $this->pending[$k] = $body;

        return true;
    }

    /**
     * Marks every object {@see self::putObject()} staged this session as durably published,
     * by writing each one's local "already published" marker, which is also the mirrored copy
     * a later merge starts from when the remote's current shard has none. Call this once, right after the
     * remote's `end()`, never before: `end()` is the one call that actually confirms a git
     * push landed (`put()` alone never does, see {@see self::putObject()}'s docblock).
     *
     * A no-op — writes nothing, returns 0 — whenever {@see RemoteCache::lastError()} reports
     * an error. Checked here, inside ObjectStore, rather than left to the caller: a caller
     * that forgets the check can then never turn a failed push into permanent data loss.
     * This is the deliberately safe direction, and it is asymmetric on purpose — skipping a
     * marker here costs at most one `put` of the same key on the next run, which merges
     * with what the remote holds and changes nothing a reader sees; writing one for a push that
     * silently failed would make it the merge base of a later publish of the same key without
     * the remote ever having had it. When in doubt, this method does not write the marker.
     *
     * @return int how many markers were actually written this call
     */
    public function confirmPublished(): int
    {
        if ($this->pending === [] || $this->remote->lastError() !== null) {
            return 0;
        }

        $written = 0;

        foreach ($this->pending as $k => $body) {
            if (AtomicFile::write($this->mirrorPath($k), $body)) {
                $written++;
            }
        }

        $this->pending = [];

        return $written;
    }

    /** @return list<string> `yyyy-mm` shards, newest first */
    public function shards(?int $now = null): array
    {
        $now ??= time();
        $shards = [];

        for ($i = 0; $i <= self::SHARD_LOOKBACK; $i++) {
            $shards[] = date('Y-m', (int) strtotime('-' . $i . ' month', $now));
        }

        return array_values(array_unique($shards));
    }

    public static function currentShard(?int $now = null): string
    {
        return date('Y-m', $now ?? time());
    }

    /** `<stateDir>/remote/cache/objects/<k>.json` — the flat local mirror. */
    public function mirrorPath(string $k): string
    {
        return self::mirrorRoot($this->stateDir) . '/' . $k . '.json';
    }

    /** `<stateDir>/remote/cache/objects` — the one flat directory {@see self::mirrorPath()} keys into. */
    public static function mirrorRoot(string $stateDir): string
    {
        return rtrim($stateDir, '/') . '/remote/cache/objects';
    }

    /**
     * The local mirror's state against `$reachable`, without changing anything on disk —
     * what {@see self::collectMirror()} would do, for `status` (docs/proposals/remote-
     * layout.md §6).
     *
     * @param array<string, true> $reachable content keys the local graph can still address
     * @return array{objects: int, reachable: int, reclaimableBytes: int}
     */
    public static function mirrorStats(string $stateDir, array $reachable): array
    {
        $reachableCount = 0;
        $reclaimable = 0;
        $entries = self::scanMirror($stateDir, $reachable);

        foreach ($entries as $entry) {
            if ($entry['reachable']) {
                $reachableCount++;
            } else {
                $reclaimable += $entry['bytes'];
            }
        }

        return [
            'objects' => count($entries),
            'reachable' => $reachableCount,
            'reclaimableBytes' => $reclaimable,
        ];
    }

    /**
     * Collects every mirrored object `$reachable` no longer addresses (docs/proposals/
     * remote-layout.md §§2-4): truncated to zero bytes by default, which frees the disk
     * blocks while leaving {@see self::putObject()}'s "already published" marker intact —
     * `is_file()` stays true, only the now-empty body makes {@see self::object()} fail
     * {@see self::decodeObject()} and fall through to the remote, refilling the file exactly
     * as a first read would have. `$forgetPublished` unlinks instead, reclaiming the inode at
     * the cost of one `put` if this machine ever addresses that key again (merged with the
     * remote's copy, so it adds nothing a reader would not already find) — never data loss,
     * since the mirror is a copy, never the only one.
     *
     * @param array<string, true> $reachable content keys the local graph can still address
     * @return array{objects: int, reachable: int, evicted: int, reclaimedBytes: int}
     */
    public static function collectMirror(string $stateDir, array $reachable, bool $forgetPublished): array
    {
        $reachableCount = 0;
        $evicted = 0;
        $reclaimed = 0;
        $entries = self::scanMirror($stateDir, $reachable);

        foreach ($entries as $entry) {
            if ($entry['reachable']) {
                $reachableCount++;

                continue;
            }

            $ok = $forgetPublished ? @unlink($entry['path']) : AtomicFile::write($entry['path'], '');

            if ($ok) {
                $evicted++;
                $reclaimed += $entry['bytes'];
            }
        }

        return [
            'objects' => count($entries),
            'reachable' => $reachableCount,
            'evicted' => $evicted,
            'reclaimedBytes' => $reclaimed,
        ];
    }

    /**
     * Every object currently in the local mirror, classified against `$reachable`. The
     * mirror is flat ({@see self::mirrorRoot()}), so one `glob()` is the whole inventory —
     * no shard tree to walk, unlike the remote itself.
     *
     * @param array<string, true> $reachable
     * @return list<array{key: string, path: string, bytes: int, reachable: bool}>
     */
    private static function scanMirror(string $stateDir, array $reachable): array
    {
        $entries = [];

        foreach (glob(self::mirrorRoot($stateDir) . '/*.json') ?: [] as $path) {
            $key = basename($path, '.json');
            $bytes = @filesize($path);

            $entries[] = [
                'key' => $key,
                'path' => $path,
                'bytes' => $bytes !== false ? $bytes : 0,
                'reachable' => isset($reachable[$key]),
            ];
        }

        return $entries;
    }

    /** The full key of an object in a shard older than the lookback window, when listable. */
    private function findInListing(string $k): ?string
    {
        $this->listing ??= $this->remote->keys('objects/');

        $suffix = '/' . $k . '.json';

        foreach ($this->listing as $key) {
            if (str_ends_with($key, $suffix)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return RemoteObject|null
     */
    private static function decodeObject(string $body, string $expectedKey): ?array
    {
        $data = Json::decodeArray($body);

        if ($data === null) {
            return null;
        }

        $k = $data['k'] ?? null;

        if (! is_string($k) || $k !== $expectedKey) {
            return null;
        }

        $file = $data['file'] ?? null;
        $results = self::decodeResults($data['results'] ?? null);

        if (! is_string($file) || $file === '' || $results === []) {
            return null;
        }

        $n = $data['n'] ?? null;
        $at = $data['at'] ?? null;
        $variants = [];

        foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $digest => $variant) {
            if (! is_string($digest) || $digest === '' || ! is_array($variant)) {
                continue;
            }

            $variantResults = self::decodeResults($variant['results'] ?? null);

            if ($variantResults !== []) {
                $variants[$digest] = ['at' => is_int($variant['at'] ?? null) ? $variant['at'] : 0, 'results' => $variantResults];
            }
        }

        $aliases = [];

        foreach (is_array($data['aliases'] ?? null) ? $data['aliases'] : [] as $digest => $alias) {
            if (is_string($digest) && $digest !== '' && is_array($alias) && is_string($alias['of'] ?? null)) {
                $aliases[$digest] = ['of' => $alias['of'], 'at' => is_int($alias['at'] ?? null) ? $alias['at'] : 0];
            }
        }

        return [
            'k' => $k,
            'file' => $file,
            'n' => is_string($n) && $n !== '' ? $n : null,
            'at' => is_int($at) ? $at : 0,
            'results' => $results,
            'variants' => $variants,
            'aliases' => $aliases,
        ];
    }

    /**
     * `$existing` with `$results` as its newest variant, the one at the top level; the others
     * move under `variants`, newest first, {@see self::VARIANTS} in all. A top level written
     * without a digest (before digests existed) is not kept as a variant: it names none.
     *
     * @param RemoteObject|null $existing
     * @param array<string, TestResultArray> $results
     * @return array<string, mixed>
     */
    private static function withVariant(?array $existing, string $k, string $file, array $results, ?string $digest, int $now): array
    {
        if ($digest === null) {
            return ['k' => $k, 'file' => $file, 'results' => $results];
        }

        $entries = $existing === null ? [] : self::entriesOf($existing);

        foreach ($entries as $entry) {
            if (isset($entry['results']) && self::sameOutcome($entry['results'], $results)) {
                $entries[] = ['d' => $digest, 'at' => $now, 'of' => $entry['d']];

                return self::normalise($entries, $k, $file);
            }
        }

        $entries[] = ['d' => $digest, 'at' => $now, 'results' => $results];

        return self::normalise($entries, $k, $file);
    }

    /**
     * Every digest an object names, as a flat list: variants with results, aliases with the
     * digest whose results they share. A top level without a digest names none.
     *
     * @param RemoteObject $object
     * @return list<array{d: string, at: int, results?: array<string, TestResultArray>, of?: string}>
     */
    private static function entriesOf(array $object): array
    {
        $entries = [];

        if ($object['n'] !== null) {
            $entries[] = ['d' => $object['n'], 'at' => $object['at'], 'results' => $object['results']];
        }

        foreach ($object['variants'] as $digest => $variant) {
            $entries[] = ['d' => (string) $digest, 'at' => $variant['at'], 'results' => $variant['results']];
        }

        foreach ($object['aliases'] as $digest => $alias) {
            $entries[] = ['d' => (string) $digest, 'at' => $alias['at'], 'of' => $alias['of']];
        }

        return $entries;
    }

    /**
     * The body for a set of entries: the newest one with results at the top level, the other
     * variants and the aliases beside it, {@see self::VARIANTS} digests in all, newest first; an
     * alias whose variant did not make the cut goes with it. A digest named twice keeps its
     * newest entry.
     *
     * @param list<array{d: string, at: int, results?: array<string, TestResultArray>, of?: string}> $entries
     * @return array<string, mixed>
     */
    private static function normalise(array $entries, string $k, string $file): array
    {
        // Newest first; within one second, the one listed later (the one being published).
        $order = array_keys($entries);
        usort($order, static fn (int $a, int $b): int => [$entries[$b]['at'], $b] <=> [$entries[$a]['at'], $a]);

        $kept = [];

        foreach (array_map(static fn (int $i): array => $entries[$i], $order) as $entry) {
            if (! isset($kept[$entry['d']]) && count($kept) < self::VARIANTS) {
                $kept[$entry['d']] = $entry;
            }
        }

        $object = ['k' => $k, 'file' => $file];
        $variants = [];
        $aliases = [];

        foreach ($kept as $digest => $entry) {
            if (isset($entry['results'])) {
                if (! isset($object['n'])) {
                    $object += ['n' => (string) $digest, 'at' => $entry['at'], 'results' => $entry['results']];
                } else {
                    $variants[(string) $digest] = ['at' => $entry['at'], 'results' => $entry['results']];
                }
            }
        }

        foreach ($kept as $digest => $entry) {
            $of = $entry['of'] ?? null;

            if ($of !== null && (($object['n'] ?? null) === $of || isset($variants[$of]))) {
                $aliases[(string) $digest] = ['of' => $of, 'at' => $entry['at']];
            }
        }

        if ($variants !== []) {
            $object['variants'] = $variants;
        }

        if ($aliases !== []) {
            $object['aliases'] = $aliases;
        }

        return $object;
    }

    /**
     * Same test ids with the same status, message and assertion count: what a replay would
     * report. Times and stamps are not outcome.
     *
     * @param array<string, TestResultArray> $a
     * @param array<string, TestResultArray> $b
     */
    private static function sameOutcome(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $testId => $result) {
            $other = $b[$testId] ?? null;

            if ($other === null || $other['status'] !== $result['status'] || $other['message'] !== $result['message'] || $other['assertions'] !== $result['assertions']) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, TestResultArray> */
    private static function decodeResults(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $out = [];

        foreach ($section as $testId => $entry) {
            $id = is_string($testId) ? $testId : (string) $testId;

            if ($id === '' || ! is_array($entry) || ! is_int($entry['status'] ?? null)) {
                continue;
            }

            $time = $entry['time'] ?? null;
            $assertions = $entry['assertions'] ?? null;

            $result = [
                'status' => $entry['status'],
                'message' => is_string($entry['message'] ?? null) ? $entry['message'] : '',
                'time' => (is_int($time) || is_float($time)) ? (float) $time : 0.0,
                'assertions' => is_int($assertions) ? $assertions : 0,
            ];

            if (is_string($entry['file'] ?? null) && $entry['file'] !== '') {
                $result['file'] = $entry['file'];
            }

            if (is_string($entry['key'] ?? null) && $entry['key'] !== '') {
                $result['key'] = $entry['key'];
            }

            if (is_string($entry['digest'] ?? null) && $entry['digest'] !== '') {
                $result['digest'] = $entry['digest'];
            }

            $out[$id] = $result;
        }

        return $out;
    }

    private function warnLastError(): void
    {
        $error = $this->remote->lastError();

        if ($error !== null) {
            Warnings::warn('remote (' . $this->remote->name() . '): ' . $error);
        }
    }

    private function debug(string $message): void
    {
        Warnings::debug('remote ' . $this->remote->name() . ': ' . $message);
    }

    /** Branch names contain `/` (`feature/x`) — flattened so a branch is one key, not a tree. */
    private static function slug(string $branch): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $branch) ?? '';
        $slug = trim($slug, '-');

        return $slug === '' ? 'branch' : $slug;
    }
}
