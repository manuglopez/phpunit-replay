<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Coverage;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

/**
 * Reads and writes the per-line "who executed this line" map of
 * `SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData` in a shape-independent way.
 *
 * `ProcessedCodeCoverageData::lineCoverage()` keeps the same method signature across every
 * php-code-coverage major this package supports, but NOT the same value shape:
 *
 * - php-code-coverage 11, 12, 13 and 14.0-14.2 store the test ids inline, one line being
 *   `null` (not executable) or a `list<string>` of the ids that hit it;
 * - php-code-coverage 14.3 interns the ids: a line is `null` or an
 *   `array<test index, hit count>`, and the index => id table lives in the (14.3-only)
 *   `testIds()` accessor.
 *
 * That is a silent break, not a loud one: code that walks the old shape simply finds no
 * strings to match and concludes nothing was executed. It cost this package both its
 * dependency edges ({@see \Manuglopez\Replay\Record\PiggybackCoverageDriver}) and its
 * coverage snapshots ({@see \Manuglopez\Replay\Record\CoverageSnapshots}) on 14.3, with no
 * error anywhere. So the shape is detected from the DATA (a string value means an inline id,
 * an int key with an int value means an interned index) rather than from a version number:
 * a `ProcessedCodeCoverageData` unserialized from a file another major wrote carries that
 * major's shape regardless of which major's class code is loaded.
 */
final class LineHits
{
    /**
     * The interned index => test id table of `$data`, or null when this php-code-coverage
     * stores the ids inline in the per-line lists and has no such table.
     *
     * @return array<int, non-empty-string>|null
     */
    public static function testIds(ProcessedCodeCoverageData $data): ?array
    {
        $ids = CoverageFormat::call($data, 'testIds');

        if (! is_array($ids)) {
            return null;
        }

        $out = [];

        foreach ($ids as $index => $id) {
            if (is_int($index) && is_string($id) && $id !== '') {
                $out[$index] = $id;
            }
        }

        return $out;
    }

    /**
     * The test ids that hit one line, with their hit counts (1 for a driver that only knows
     * "executed at least once"). Empty for a line nobody hit and for the `null` marker of a
     * line that is not executable at all — callers that care about the difference check for
     * `null` themselves before asking.
     *
     * @param array<mixed>|null $hit one line's value from `lineCoverage()`
     * @param array<int, non-empty-string>|null $testIds {@see self::testIds()}
     * @return array<non-empty-string, positive-int>
     */
    public static function idsOnLine(?array $hit, ?array $testIds): array
    {
        if ($hit === null) {
            return [];
        }

        $out = [];

        foreach ($hit as $key => $value) {
            if (is_string($value)) {
                if ($value !== '') {
                    $out[$value] = max($out[$value] ?? 1, 1);
                }

                continue;
            }

            if (! is_int($key) || ! is_int($value) || $value < 1 || $testIds === null) {
                continue;
            }

            $id = $testIds[$key] ?? null;

            if ($id !== null) {
                $out[$id] = max($out[$id] ?? 1, $value);
            }
        }

        return $out;
    }

    /**
     * Whether any of `$wanted` hit this line. Separate from {@see self::idsOnLine()} because
     * `PiggybackCoverageDriver` asks this once per covered line per test and only needs the
     * yes/no answer, which short-circuits.
     *
     * @param array<mixed>|null $hit one line's value from `lineCoverage()`
     * @param array<int, non-empty-string>|null $testIds {@see self::testIds()}
     * @param array<string, true> $wanted
     */
    public static function hitByAny(?array $hit, ?array $testIds, array $wanted): bool
    {
        if ($hit === null) {
            return false;
        }

        foreach ($hit as $key => $value) {
            if (is_string($value)) {
                if (isset($wanted[$value])) {
                    return true;
                }

                continue;
            }

            if (! is_int($key) || ! is_int($value) || $value < 1 || $testIds === null) {
                continue;
            }

            $id = $testIds[$key] ?? null;

            if ($id !== null && isset($wanted[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The inverse of {@see self::idsOnLine()}: turns a shape-independent
     * `file => line => (id => hit count)` map into whatever the INSTALLED
     * php-code-coverage's `setLineCoverage()` expects, plus the index => id table its
     * `setTestIds()` needs (null when it has neither).
     *
     * @param array<string, array<int, array<non-empty-string, positive-int>|null>> $neutral
     * @return array{0: array<string, array<int, mixed>>, 1: array<int, non-empty-string>|null}
     */
    public static function toLineCoverage(array $neutral, bool $intern): array
    {
        $lineCoverage = [];
        $indexOf = [];
        $ids = [];

        foreach ($neutral as $file => $lines) {
            $out = [];

            foreach ($lines as $line => $hits) {
                if ($hits === null) {
                    $out[$line] = null;

                    continue;
                }

                if (! $intern) {
                    $out[$line] = array_keys($hits);

                    continue;
                }

                $map = [];

                foreach ($hits as $id => $count) {
                    if (! isset($indexOf[$id])) {
                        $indexOf[$id] = count($indexOf);
                        $ids[$indexOf[$id]] = $id;
                    }

                    $map[$indexOf[$id]] = max(1, $count);
                }

                $out[$line] = $map;
            }

            $lineCoverage[$file] = $out;
        }

        return [$lineCoverage, $intern ? $ids : null];
    }
}
