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
     * already has mirrored locally is skipped: objects are immutable, so re-uploading one
     * only costs bandwidth (and, on the git backend, a pointless commit).
     *
     * @param array<string, TestResultArray> $results
     */
    public function putObject(string $k, string $testFileRel, array $results): bool
    {
        if ($k === '' || $results === []) {
            return false;
        }

        $local = $this->mirrorPath($k);

        if (is_file($local)) {
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

        AtomicFile::write($local, $body);

        return true;
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
        return rtrim($this->stateDir, '/') . '/remote/cache/objects/' . $k . '.json';
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
