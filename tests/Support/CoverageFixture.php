<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Support;

use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Coverage\LineHits;
use Manuglopez\Replay\Report\NullCoverageDriver;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * Builds `CodeCoverage` objects the way php-code-coverage itself builds them, so tests see
 * whatever the INSTALLED major's native representation actually is.
 *
 * This matters more than it looks. Handing `ProcessedCodeCoverageData::setLineCoverage()` a
 * literal `[$file => [3 => ['A::a']]]` — which is what these tests used to do — stores that
 * array verbatim on every major, so such a test keeps passing on php-code-coverage 14.3 while
 * the production code silently breaks: 14.3 no longer puts test id strings in the per-line
 * maps at all, it interns them behind `testIds()` ({@see LineHits}). Going through
 * `initializeUnseenData()` + `markCodeAsExecutedByTestCase()` — the exact pair
 * `CodeCoverage::append()` calls with a driver's raw output — produces the real shape in every
 * cell, and only then does a test prove anything about the coverage this package will meet in
 * the field.
 */
final class CoverageFixture
{
    /** Not executable at all (`null` in the processed data). */
    public const DEAD = Driver::LINE_NOT_EXECUTABLE;

    /** Executable but not executed (`[]` in the processed data). */
    public const MISSED = Driver::LINE_NOT_EXECUTED;

    /** Executed once. */
    public const HIT = Driver::LINE_EXECUTED;

    /**
     * @param array<string, array<string, array<int, int>>> $byTest test id => file => line => one
     *        of the constants above (or a hit count > 1, which only a php-code-coverage that
     *        records them keeps)
     * @param array<string, array{size: string, status: string, time: float}> $tests
     */
    public static function native(array $byTest, array $tests): CodeCoverage
    {
        $files = [];

        foreach ($byTest as $perFile) {
            foreach (array_keys($perFile) as $file) {
                $files[$file] = true;
            }
        }

        $filter = new Filter();
        $filter->includeFiles(array_keys($files));

        $data = CoverageFormat::newProcessedData(false);

        foreach ($byTest as $testId => $perFile) {
            $raw = RawCodeCoverageData::fromXdebugWithPathCoverage(
                array_map(
                    static fn (array $lines): array => ['lines' => $lines, 'functions' => []],
                    $perFile,
                ),
            );

            $data->initializeUnseenData($raw);
            $data->markCodeAsExecutedByTestCase($testId, $raw);
        }

        $coverage = new CodeCoverage(new NullCoverageDriver('fixture', '1'), $filter);
        $coverage->setData($data);
        $coverage->setTests($tests);

        return $coverage;
    }

    /**
     * `file => line => list<test id>`, sorted, whatever the installed representation is: the
     * shape-independent view a test can assert against. `null` is kept for a line that is not
     * executable.
     *
     * @return array<string, array<int, list<string>|null>>
     */
    public static function neutral(CodeCoverage $coverage): array
    {
        $data = $coverage->getData(true);
        $testIds = LineHits::testIds($data);
        $out = [];

        foreach ($data->lineCoverage() as $file => $lines) {
            foreach ($lines as $line => $hit) {
                if ($hit === null) {
                    $out[$file][$line] = null;

                    continue;
                }

                $ids = array_keys(LineHits::idsOnLine(is_array($hit) ? $hit : [], $testIds));
                sort($ids);
                $out[$file][$line] = $ids;
            }
        }

        return $out;
    }

    /**
     * The line numbers of `$file` that anybody executed.
     *
     * @return list<int>
     */
    public static function executedLines(CodeCoverage $coverage, string $file): array
    {
        $out = [];

        foreach (self::neutral($coverage)[$file] ?? [] as $line => $ids) {
            if ($ids !== null && $ids !== []) {
                $out[] = $line;
            }
        }

        sort($out);

        return $out;
    }
}
