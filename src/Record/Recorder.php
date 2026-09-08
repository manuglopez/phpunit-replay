<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use Manuglopez\Replay\Analysis\FactsCache;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Recorder.php
 *
 * Simplified: coverage capture is delegated entirely to the injected CoverageDriver
 * (already filtered by SourceScope), and only file-level edges/tables are tracked —
 * no Inertia/Livewire/database-trait detection.
 */
final class Recorder
{
    private ?string $currentTestFile = null;

    /** @var array<string, array<string, true>> */
    private array $perTestFiles = [];

    /** @var array<string, array<string, true>> */
    private array $perTestTables = [];

    /**
     * `$facts` is the `static_declaration_edges` opt-in (SPEC.md §9). When null — the
     * default, and every existing caller — {@see self::filesWithExecutedLines()} behaves
     * exactly as it always has.
     */
    public function __construct(
        private readonly CoverageDriver $driver,
        private readonly ?FactsCache $facts = null,
    ) {
    }

    /**
     * Starts recording for the given test file.
     *
     * PHPUnit only emits `Test\Finished` when the test `wasPrepared()` (see
     * `TestCase::runBare()`): a test whose `setUp()` throws Skipped/IncompleteTest is
     * never marked as prepared, so `Test\Finished` never arrives for it and `endTest()`
     * is never called for it either. If a previous test is still open when this is
     * called again, its pending coverage is attributed to that previous test file
     * (via an implicit `endTest()`) before opening the new one — it is never silently
     * dropped.
     */
    public function beginTest(string $testFileAbsolute): void
    {
        if ($this->currentTestFile !== null) {
            $this->endTest();
        }

        if ($testFileAbsolute === '' || $testFileAbsolute === 'unknown' || str_contains($testFileAbsolute, "eval()'d")) {
            return;
        }

        $this->currentTestFile = $testFileAbsolute;
        $this->driver->start();
    }

    public function endTest(): void
    {
        if ($this->currentTestFile === null) {
            return;
        }

        $data = $this->driver->stop();
        $testFile = $this->currentTestFile;
        $this->currentTestFile = null;

        foreach ($this->filesWithExecutedLines($data) as $sourceFile) {
            $this->perTestFiles[$testFile][$sourceFile] = true;
        }
    }

    public function currentTestFile(): ?string
    {
        return $this->currentTestFile;
    }

    public function linkSource(string $absoluteSourceFile): void
    {
        if ($this->currentTestFile === null || $absoluteSourceFile === '') {
            return;
        }

        $this->perTestFiles[$this->currentTestFile][$absoluteSourceFile] = true;
    }

    public function linkTable(string $table): void
    {
        if ($this->currentTestFile === null || $table === '') {
            return;
        }

        $this->perTestTables[$this->currentTestFile][strtolower($table)] = true;
    }

    /** @return array<string, list<string>> test file (abs) => source files (abs) */
    public function perTestFiles(): array
    {
        $out = [];

        foreach ($this->perTestFiles as $testFile => $sources) {
            $out[$testFile] = array_keys($sources);
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    public function perTestTables(): array
    {
        $out = [];

        foreach ($this->perTestTables as $testFile => $tables) {
            $names = array_keys($tables);
            sort($names);
            $out[$testFile] = $names;
        }

        return $out;
    }

    public function reset(): void
    {
        $this->currentTestFile = null;
        $this->perTestFiles = [];
        $this->perTestTables = [];
    }

    /**
     * File-level reduction: which of the files the driver reported count as dependencies of
     * the test that just ran.
     *
     * Two strategies, and which one applies is per-file, not per-run:
     *
     *  - **Behavioural** (`static_declaration_edges` on, and the file parses): the file
     *    counts only when at least one executed line falls inside a function/method/closure
     *    body ({@see \Manuglopez\Replay\Analysis\FileFacts::coversAnyBodyLine()}). Those
     *    lines run when something *calls* them, so the attribution is the same in every
     *    process and every worker distribution. A file whose only coverage is its own
     *    top level was merely loaded, and PHP loads it once per process — that credit
     *    belongs to no test in particular, and `Analysis\StaticEdges` replaces it with a
     *    name-resolution edge instead.
     *  - **The Pest heuristic** (the default, and the fallback for any file php-parser
     *    cannot read): a file counts if it has a line with hits > 0, except when the driver
     *    also reports unexecuted lines and the only executed line is the highest-numbered
     *    one. The behavioural test supersedes it wherever it applies, but it is kept — not
     *    deleted — because "unparseable" must not collapse into "no dependencies". A syntax
     *    error in one file silently dropping every edge it has is the exact false green
     *    this whole mechanism exists to remove, so an unclassifiable file keeps the
     *    behaviour it has today.
     *
     * @param array<string, array<int, int>> $data
     * @return list<string>
     */
    private function filesWithExecutedLines(array $data): array
    {
        $out = [];

        foreach ($data as $file => $lines) {
            $covered = [];

            foreach ($lines as $line => $count) {
                if ($count > 0) {
                    $covered[] = $line;
                }
            }

            if ($covered === []) {
                continue;
            }

            if ($this->facts !== null) {
                $facts = $this->facts->for($file);

                if ($facts->parsed) {
                    if ($facts->coversAnyBodyLine($covered)) {
                        $out[] = $file;
                    }

                    continue;
                }
            }

            $lineKeys = array_keys($lines);
            $reportsUnexecutedLines = count($covered) < count($lines);

            if ($reportsUnexecutedLines && $lineKeys !== [] && count($covered) === 1 && $covered[0] === max($lineKeys)) {
                continue;
            }

            $out[] = $file;
        }

        return $out;
    }
}
