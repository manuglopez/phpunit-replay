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
 * Skeleton: only the read side (`load`/`isQuarantined`/`testIds`/`all`) plus `save`
 * and `clear` are implemented. Flip detection (`recordFlip`/`recordStable`/`release`)
 * belongs to the hermeticity work and is not implemented here.
 *
 * @phpstan-type QuarantineEntry array{firstSeen: int, flips: int, stable: int, lastKey: string, reason: string}
 */
final class Quarantine
{
    /** @var array<string, QuarantineEntry> */
    private array $entries = [];

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

    public function isQuarantined(string $testId): bool
    {
        return array_key_exists($testId, $this->entries);
    }

    /** @return list<string> */
    public function testIds(): array
    {
        return array_keys($this->entries);
    }

    /** @return array<string, QuarantineEntry> */
    public function all(): array
    {
        return $this->entries;
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
