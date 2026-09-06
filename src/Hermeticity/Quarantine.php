<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Hermeticity;

use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;

/**
 * Tests that are known to flip and must therefore always execute (SPEC.md §8.3),
 * persisted as `<stateDir>/flaky.json`:
 *
 *   {"<testId>": {"firstSeen": unix, "flips": n, "stable": n, "lastKey": "k", "reason": "flip"}}
 *
 * A test is quarantined while `flips >= 1`. Consecutive stable passes (unchanged content
 * key, unchanged result class) accumulate `stable`; once it reaches `quarantine_release_after`
 * the entry is released automatically ({@see self::recordStable()}), the same as
 * `phpunit-replay prune --flaky` releases everything by clearing the whole map.
 *
 * @phpstan-type QuarantineEntry array{firstSeen: int, flips: int, stable: int, lastKey: string, reason: string}
 */
final class Quarantine
{
    /** @var array<string, QuarantineEntry> */
    private array $entries = [];

    private int $releaseAfter = 20;

    public static function load(string $stateDir): self
    {
        $quarantine = new self();

        $raw = AtomicFile::read(self::path($stateDir));

        if ($raw === null) {
            return $quarantine;
        }

        $decoded = Json::decodeArray($raw);

        if ($decoded === null) {
            return $quarantine;
        }

        foreach ($decoded as $testId => $entry) {
            if (! is_string($testId) || $testId === '' || ! is_array($entry)) {
                continue;
            }

            $quarantine->entries[$testId] = [
                'firstSeen' => is_int($entry['firstSeen'] ?? null) ? $entry['firstSeen'] : 0,
                'flips' => is_int($entry['flips'] ?? null) ? $entry['flips'] : 0,
                'stable' => is_int($entry['stable'] ?? null) ? $entry['stable'] : 0,
                'lastKey' => is_string($entry['lastKey'] ?? null) ? $entry['lastKey'] : '',
                'reason' => is_string($entry['reason'] ?? null) ? $entry['reason'] : 'flip',
            ];
        }

        return $quarantine;
    }

    public function save(string $stateDir): bool
    {
        $json = Json::encodePretty($this->entries);

        if ($json === null) {
            return false;
        }

        return AtomicFile::write(self::path($stateDir), $json);
    }

    /** How many consecutive stable passes release a quarantined test automatically (SPEC.md §8.3, default 20). */
    public function setReleaseAfter(int $n): void
    {
        $this->releaseAfter = max(1, $n);
    }

    public function isQuarantined(string $testId): bool
    {
        $entry = $this->entries[$testId] ?? null;

        return $entry !== null && $entry['flips'] >= 1;
    }

    /** @return list<string> ids currently quarantined ({@see self::isQuarantined()}), not every known entry */
    public function testIds(): array
    {
        $ids = [];

        foreach ($this->entries as $testId => $entry) {
            if ($entry['flips'] >= 1) {
                $ids[] = $testId;
            }
        }

        return $ids;
    }

    /**
     * Every known entry, quarantined or already released (for `status`/introspection).
     *
     * @return array<string, QuarantineEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /** A cached result flipped class (pass↔fail, pass↔error, ...) against the same content key: quarantine it. */
    public function recordFlip(string $testId, string $key, string $reason = 'flip'): void
    {
        $existing = $this->entries[$testId] ?? null;
        $now = time();

        $this->entries[$testId] = [
            'firstSeen' => $existing['firstSeen'] ?? $now,
            'flips' => ($existing['flips'] ?? 0) + 1,
            'stable' => 0,
            'lastKey' => $key,
            'reason' => $reason,
        ];
    }

    /** A pass produced the same content key and the same result class again: one step closer to release. No-op for an unknown id. */
    public function recordStable(string $testId): void
    {
        $existing = $this->entries[$testId] ?? null;

        if ($existing === null) {
            return;
        }

        $existing['stable']++;
        $this->entries[$testId] = $existing;

        if ($existing['stable'] >= $this->releaseAfter) {
            $this->release($testId);
        }
    }

    /** Clears the flip count so the entry is no longer quarantined, keeping its history. No-op for an unknown id. */
    public function release(string $testId): void
    {
        if (! isset($this->entries[$testId])) {
            return;
        }

        $this->entries[$testId]['flips'] = 0;
    }

    public function clear(): void
    {
        $this->entries = [];
    }

    private static function path(string $stateDir): string
    {
        return rtrim($stateDir, '/') . '/flaky.json';
    }
}
