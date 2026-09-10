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
 * Objects are content-addressed and append-only, so the month in the key is only a shard
 * (the month the object was first written), never part of its identity. Lookups therefore
 * try the current month and the previous five by name — six cheap `get`s, no listing —
 * and only fall back to a full `keys()` scan on backends that support one.
 *
 * Everything read is mirrored under `<stateDir>/remote/cache/objects/<k>.json`, flat: `k`
 * is already unique, so the local mirror needs no shard and a second lookup for the same
 * key costs one `is_file()`. That mirror is also what makes a re-`put` of an object this
 * machine already knows about a no-op. Graphs are mutable and are never mirrored.
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
 * @phpstan-import-type TestResultArray from Graph
 * @phpstan-type RemoteObject array{k: string, file: string, results: array<string, TestResultArray>}
 */
final class ObjectStore
{
    /** @var int months before the current one an object lookup probes by name */
    private const SHARD_LOOKBACK = 5;

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
    public function object(string $k): ?array
    {
        if ($k === '') {
            return null;
        }

        $local = $this->mirrorPath($k);
        $cached = AtomicFile::read($local);

        if ($cached !== null) {
            $decoded = self::decodeObject($cached, $k);

            if ($decoded !== null) {
                $this->debug('hit (local mirror) objects/*/' . $k . '.json');

                return $decoded;
            }
        }

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
     * Publishes the results of one test file under its content key. A key this machine
     * already has mirrored locally (durably confirmed, {@see self::confirmPublished()}) or
     * already staged this very session is skipped: objects are immutable, so re-uploading one
     * only costs bandwidth (and, on the git backend, a pointless commit).
     *
     * @param array<string, TestResultArray> $results
     */
    public function putObject(string $k, string $testFileRel, array $results): bool
    {
        if ($k === '' || $results === []) {
            return false;
        }

        if (isset($this->pending[$k]) || is_file($this->mirrorPath($k))) {
            $this->debug('skip (already published) objects/*/' . $k . '.json');

            return false;
        }

        $body = Json::encode(['k' => $k, 'file' => $testFileRel, 'results' => $results]);

        if ($body === null) {
            return false;
        }

        $key = self::objectKey(self::currentShard(), $k);
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
     * by writing each one's local "already published" marker — the very file
     * {@see self::putObject()}'s own skip check reads. Call this once, right after the
     * remote's `end()`, never before: `end()` is the one call that actually confirms a git
     * push landed (`put()` alone never does, see {@see self::putObject()}'s docblock).
     *
     * A no-op — writes nothing, returns 0 — whenever {@see RemoteCache::lastError()} reports
     * an error. Checked here, inside ObjectStore, rather than left to the caller: a caller
     * that forgets the check can then never turn a failed push into permanent data loss.
     * This is the deliberately safe direction, and it is asymmetric on purpose — skipping a
     * marker here costs one redundant `put` of the same key on the next run, a documented
     * no-op because objects are content-addressed and append-only
     * (docs/sharing-the-cache.md: "two machines writing the same key at the same time is a
     * no-op, never a conflict"); writing one for a push that silently failed costs that
     * object forever, because {@see self::putObject()} never puts a key its marker already
     * exists for. When in doubt, this method does not write the marker.
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
     * the cost of one redundant, harmless `put` if this machine ever addresses that key again
     * (objects are content-addressed and append-only) — never data loss, since the marker
     * this drops is a re-publish optimisation, never the only copy of the object.
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

        return ['k' => $k, 'file' => $file, 'results' => $results];
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
