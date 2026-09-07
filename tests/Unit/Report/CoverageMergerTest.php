<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Report;

use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Coverage\LineHits;
use Manuglopez\Replay\Coverage\Snapshot;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Report\CoverageMerger;
use Manuglopez\Replay\Tests\Support\CoverageFixture;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * Deliverable 4 (unit): a run's `--coverage-php` file and a stored snapshot merged into one,
 * and the resulting line counts checked directly — no PHPUnit process, no fixture project.
 *
 * Nothing here writes or parses a `--coverage-php` file by hand any more: the run coverage
 * goes through `Coverage\CoverageFormat::archive()`, exactly as PHPUnit's own writer and this
 * package's reader do, so the test exercises whichever of the two mutually unreadable formats
 * the installed php-code-coverage actually uses.
 */
final class CoverageMergerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = TempDir::make('coverage-merger');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function merge_combines_line_hits_from_the_run_and_a_snapshot(): void
    {
        $fileA = $this->dir . '/src/A.php';
        $fileB = $this->dir . '/src/B.php';

        $runPath = $this->writeRun(CoverageFixture::native(
            ['T1' => [$fileA => [
                1 => CoverageFixture::DEAD,
                2 => CoverageFixture::MISSED,
                3 => CoverageFixture::HIT,
                4 => CoverageFixture::HIT,
            ]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.01]],
        ));

        $snapshotPath = $this->writeSnapshot(CoverageFixture::native(
            ['T2' => [$fileB => [9 => CoverageFixture::DEAD, 10 => CoverageFixture::HIT]]],
            ['T2' => ['size' => 'small', 'status' => 'passed', 'time' => 0.02]],
        ), ['T2']);

        $outputPath = $this->dir . '/merged.php';

        self::assertTrue(CoverageMerger::merge($runPath, [$snapshotPath], $outputPath));
        self::assertFileExists($outputPath);

        $lines = CoverageFixture::neutral($this->read($outputPath));

        self::assertSame(['T1'], $lines[$fileA][3]);
        self::assertSame(['T1'], $lines[$fileA][4]);
        self::assertSame(['T2'], $lines[$fileB][10]);
        self::assertNull($lines[$fileA][1]);
        self::assertSame([], $lines[$fileA][2]);
        self::assertNull($lines[$fileB][9]);

        self::assertSame(['T1', 'T2'], array_keys($this->read($outputPath)->getTests()));
    }

    #[Test]
    public function merge_unions_hits_on_the_same_line_from_two_different_tests(): void
    {
        $file = $this->dir . '/src/Shared.php';

        $runPath = $this->writeRun(CoverageFixture::native(
            ['T1' => [$file => [5 => CoverageFixture::HIT]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.01]],
        ));

        $snapshotPath = $this->writeSnapshot(CoverageFixture::native(
            ['T2' => [$file => [5 => CoverageFixture::HIT]]],
            ['T2' => ['size' => 'small', 'status' => 'passed', 'time' => 0.02]],
        ), ['T2']);

        $outputPath = $this->dir . '/merged.php';
        self::assertTrue(CoverageMerger::merge($runPath, [$snapshotPath], $outputPath));

        self::assertSame(['T1', 'T2'], CoverageFixture::neutral($this->read($outputPath))[$file][5]);
    }

    #[Test]
    public function merge_returns_false_when_the_run_coverage_file_is_missing(): void
    {
        self::assertFalse(CoverageMerger::merge($this->dir . '/missing.php', [], $this->dir . '/out.php'));
    }

    #[Test]
    public function merge_ignores_a_missing_or_corrupt_snapshot(): void
    {
        $runPath = $this->writeRun(CoverageFixture::native(
            ['T1' => [$this->dir . '/src/A.php' => [1 => CoverageFixture::HIT]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        ));

        $corrupt = $this->dir . '/corrupt.cov';
        TempDir::write($corrupt, 'not a snapshot at all');

        $outputPath = $this->dir . '/merged.php';
        $ok = CoverageMerger::merge($runPath, [$this->dir . '/does-not-exist.cov', $corrupt], $outputPath);

        self::assertTrue($ok, 'an unreadable snapshot is skipped, it does not fail the merge');
        self::assertArrayHasKey('T1', $this->read($outputPath)->getTests());
    }

    /**
     * The defensive path of work item 2(c): a `--coverage-php` file written by a
     * php-code-coverage this installation cannot read (a pre-14 file under 14, a 14 file under
     * 13, or a 14 file whose format number has moved) must not throw and must not produce a
     * corrupt merge. The user gets PHPUnit's own file at the path they asked for, plus a
     * warning that the replayed test files are missing from it.
     */
    #[Test]
    public function merge_passes_a_foreign_format_run_coverage_through_untouched(): void
    {
        $runPath = $this->dir . '/coverage.php';
        $body = "<?php // phpunit/php-code-coverage serialization format 99\nreturn ['from the future'];\n";
        TempDir::write($runPath, $body);

        $outputPath = $this->dir . '/merged.php';

        self::assertTrue(CoverageMerger::merge($runPath, [], $outputPath));
        self::assertSame($body, file_get_contents($outputPath), 'copied verbatim, not re-serialized');
    }

    /**
     * The other half of the defensive path: a run coverage file that IS in this installation's
     * own format but whose content cannot be read is a genuine failure, not a foreign format,
     * so it must not be copied through as if it were fine.
     *
     * The file is produced by the archive and then corrupted below its first line, so it
     * carries whichever marker this installation writes — none on php-code-coverage 11-13, an
     * exact version on 14.0/14.1, a format number on 14.2+. Building that first line by hand
     * from `serializationFormat()` is what made this test wrong on 14.0.0, where the number is
     * null but the file shape is emphatically not the pre-14 one: the hand-built file came out
     * unmarked, which on 14.0 is FOREIGN, so `merge()` correctly copied it through and returned
     * true while the test still expected false.
     */
    #[Test]
    public function merge_returns_false_when_the_run_coverage_is_this_format_but_unreadable(): void
    {
        $runPath = $this->writeRun(CoverageFixture::native(
            ['T1' => [$this->dir . '/src/A.php' => [1 => CoverageFixture::HIT]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        ));

        $firstLine = strtok((string) file_get_contents($runPath), "\n");
        TempDir::write($runPath, $firstLine . "\nreturn 'not coverage data';\n");

        self::assertFalse(CoverageFormat::isForeign($runPath), 'still this installation\'s own format');

        $outputPath = $this->dir . '/merged.php';
        self::assertFalse(CoverageMerger::merge($runPath, [], $outputPath));
        self::assertFileDoesNotExist($outputPath, 'nothing is written when the run coverage cannot be read');
    }

    #[Test]
    public function write_empty_run_builds_a_coverage_report_scoped_to_source_without_a_real_driver(): void
    {
        mkdir($this->dir . '/src');
        file_put_contents($this->dir . '/src/A.php', "<?php\n");

        $xmlPath = $this->dir . '/phpunit.xml';
        file_put_contents($xmlPath, <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <source>
                    <include>
                        <directory>{$this->dir}/src</directory>
                    </include>
                </source>
            </phpunit>
            XML);

        $reader = ConfigurationReader::fromXmlFile($xmlPath);
        $outputPath = $this->dir . '/empty.php';

        self::assertTrue(CoverageMerger::writeEmptyRun($outputPath, $reader));
        self::assertFileExists($outputPath);
        self::assertFalse(CoverageFormat::isForeign($outputPath), 'it is a file the installed php-code-coverage reads');

        self::assertSame([], $this->read($outputPath)->getData(true)->lineCoverage(), 'nothing executed: no line hits of its own');

        // And it is still a valid target for merge(): folding a snapshot in works exactly
        // as it would against a run coverage a real PHPUnit process produced.
        $fileA = $this->dir . '/src/A.php';
        $snapshotPath = $this->writeSnapshot(CoverageFixture::native(
            ['T1' => [$fileA => [1 => CoverageFixture::HIT]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        ), ['T1']);

        $mergedPath = $this->dir . '/merged.php';
        self::assertTrue(CoverageMerger::merge($outputPath, [$snapshotPath], $mergedPath));

        self::assertSame(['T1'], CoverageFixture::neutral($this->read($mergedPath))[$fileA][1]);
    }

    private function writeRun(CodeCoverage $coverage): string
    {
        $path = $this->dir . '/coverage.php';

        self::assertTrue(CoverageFormat::archive()->write($path, $coverage));

        return $path;
    }

    /** @param list<string> $wantedIds */
    private function writeSnapshot(CodeCoverage $coverage, array $wantedIds): string
    {
        $data = $coverage->getData(true);
        $snapshot = Snapshot::restrict($data->lineCoverage(), LineHits::testIds($data), $coverage->getTests(), $wantedIds);

        self::assertNotNull($snapshot);

        $encoded = $snapshot->encode();
        self::assertIsString($encoded);

        $path = $this->dir . '/snapshot-' . count(glob($this->dir . '/*.cov') ?: []) . '.cov';
        TempDir::write($path, $encoded);

        return $path;
    }

    private function read(string $path): CodeCoverage
    {
        $coverage = CoverageFormat::archive()->read($path);

        self::assertNotNull($coverage, $path . ' should be readable by the installed php-code-coverage');

        return $coverage;
    }
}
