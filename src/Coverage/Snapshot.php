<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Coverage;

use Manuglopez\Replay\Report\NullCoverageDriver;
use Manuglopez\Replay\Support\Json;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * One test file's slice of a run's coverage, in a representation that does not belong to any
 * php-code-coverage major: `file => line => (test id => hit count)`, with `null` for a line
 * that is not executable at all, plus the `getTests()` entries of the test ids it covers.
 *
 * This is what `<stateDir>/coverage/<k>.cov` holds ({@see \Manuglopez\Replay\Record\CoverageSnapshots}
 * writes it, {@see \Manuglopez\Replay\Report\CoverageMerger} reads it back). Version 1 of that
 * file was a raw `serialize()` of a php-code-coverage `CodeCoverage` object, which tied every
 * cached snapshot to the exact major that wrote it three times over: the class's own private
 * property set, the per-line value shape ({@see LineHits}), and — restored under a major whose
 * `ProcessedCodeCoverageData` has typed properties the old payload does not carry — a fatal
 * `Error` on first access rather than a readable object. Storing the data instead of the
 * object removes all three, so a snapshot recorded under one php-code-coverage still merges
 * cleanly under the next; only {@see CoverageFormat::SNAPSHOT_FORMAT} would ever invalidate it.
 *
 * A snapshot deliberately keeps the `null` markers of lines nobody in this test file hit, so a
 * later merge can still tell dead code apart from code that merely went unexercised, and
 * deliberately keeps hit counts, so a php-code-coverage that records them (14.3+ with xdebug)
 * does not have a whole run's counts flattened by folding in a replayed test file.
 */
final class Snapshot
{
    /**
     * @param array<string, array<int, array<non-empty-string, positive-int>|null>> $lines
     * @param array<non-empty-string, array<mixed>> $tests raw `CodeCoverage::getTests()` entries
     */
    private function __construct(
        private readonly array $lines,
        private readonly array $tests,
    ) {
    }

    /**
     * The slice of `$lineCoverage` that `$wantedIds` hit, or null when they hit nothing at all
     * (a test file that only autoloaded code contributes no snapshot).
     *
     * @param array<mixed> $lineCoverage raw `ProcessedCodeCoverageData::lineCoverage()`
     * @param array<int, non-empty-string>|null $testIds {@see LineHits::testIds()}
     * @param array<mixed> $tests raw `CodeCoverage::getTests()`
     * @param list<string> $wantedIds the test ids of one test file
     */
    public static function restrict(array $lineCoverage, ?array $testIds, array $tests, array $wantedIds): ?self
    {
        $wanted = array_fill_keys($wantedIds, true);
        $lines = [];

        foreach ($lineCoverage as $file => $fileLines) {
            if (! is_string($file) || $file === '' || ! is_array($fileLines)) {
                continue;
            }

            $kept = [];
            $anyHit = false;

            foreach ($fileLines as $line => $hit) {
                if (! is_int($line)) {
                    continue;
                }

                if ($hit === null) {
                    $kept[$line] = null;

                    continue;
                }

                if (! is_array($hit)) {
                    continue;
                }

                $restricted = array_intersect_key(LineHits::idsOnLine($hit, $testIds), $wanted);
                $kept[$line] = $restricted;

                if ($restricted !== []) {
                    $anyHit = true;
                }
            }

            if ($anyHit) {
                $lines[$file] = $kept;
            }
        }

        if ($lines === []) {
            return null;
        }

        $out = [];

        foreach (array_intersect_key($tests, $wanted) as $id => $entry) {
            if (is_string($id) && $id !== '' && is_array($entry)) {
                $out[$id] = $entry;
            }
        }

        return new self($lines, $out);
    }

    /** null when the data cannot be encoded (never expected: it is scalars all the way down). */
    public function encode(): ?string
    {
        return Json::encode([
            'format' => CoverageFormat::SNAPSHOT_FORMAT,
            'lines' => $this->lines,
            'tests' => $this->tests,
        ]);
    }

    /**
     * null for anything that is not a snapshot of a format this package understands — a
     * version 1 (serialized-object) file from an older release of this package included, which
     * is why the caller warns and skips rather than trying to salvage it.
     */
    public static function decode(string $content): ?self
    {
        $decoded = Json::decodeArray($content);

        if ($decoded === null || ($decoded['format'] ?? null) !== CoverageFormat::SNAPSHOT_FORMAT) {
            return null;
        }

        $rawLines = $decoded['lines'] ?? null;
        $rawTests = $decoded['tests'] ?? null;

        if (! is_array($rawLines)) {
            return null;
        }

        $lines = [];

        foreach ($rawLines as $file => $fileLines) {
            if (! is_string($file) || $file === '' || ! is_array($fileLines)) {
                continue;
            }

            $lines[$file] = self::decodeLines($fileLines);
        }

        if ($lines === []) {
            return null;
        }

        $tests = [];

        foreach (is_array($rawTests) ? $rawTests : [] as $id => $entry) {
            if (is_string($id) && $id !== '' && is_array($entry)) {
                $tests[$id] = $entry;
            }
        }

        return new self($lines, $tests);
    }

    /**
     * A `CodeCoverage` in the INSTALLED php-code-coverage's own representation, ready for
     * `CodeCoverage::merge()`. `$collectsHitCounts` comes from the run this snapshot is about
     * to be folded into: `ProcessedCodeCoverageData::merge()` combines the flag with `&&`, so
     * a snapshot that claimed less than the run would downgrade the whole merged report.
     *
     * The driver is a {@see NullCoverageDriver} because `merge()` reads only the filter, the
     * data and the tests off its operand — a snapshot never collects anything itself, and
     * asking `Driver\Selector` for a real one here would need pcov or xdebug loaded AND
     * enabled in the wrapper process, which is exactly the requirement that driver exists to
     * avoid.
     */
    public function toCoverage(bool $collectsHitCounts): CodeCoverage
    {
        [$lineCoverage, $testIds] = LineHits::toLineCoverage($this->lines, CoverageFormat::usesTestIndexes());

        $filter = new Filter();
        $filter->includeFiles(array_keys($lineCoverage));

        $data = CoverageFormat::newProcessedData($collectsHitCounts);
        CoverageFormat::installLineCoverage($data, $lineCoverage, $testIds);

        $coverage = new CodeCoverage(new NullCoverageDriver(), $filter);
        $coverage->setData($data);
        $coverage->setTests(CoverageFormat::testsFrom($this->tests));

        return $coverage;
    }

    /**
     * JSON object keys are strings, so line numbers come back as (numeric) array keys PHP has
     * already cast to int, and an empty hit map comes back as `[]` rather than `{}` — both
     * fine, but neither is trusted: a hand-edited or truncated snapshot must degrade to
     * "nothing recorded for that line", never to a corrupt merge.
     *
     * @param array<mixed> $fileLines
     * @return array<int, array<non-empty-string, positive-int>|null>
     */
    private static function decodeLines(array $fileLines): array
    {
        $out = [];

        foreach ($fileLines as $line => $hit) {
            if (! is_int($line)) {
                continue;
            }

            if ($hit === null) {
                $out[$line] = null;

                continue;
            }

            if (! is_array($hit)) {
                continue;
            }

            $hits = [];

            foreach ($hit as $id => $count) {
                if (is_string($id) && $id !== '' && is_int($count) && $count >= 1) {
                    $hits[$id] = $count;
                }
            }

            $out[$line] = $hits;
        }

        return $out;
    }
}
