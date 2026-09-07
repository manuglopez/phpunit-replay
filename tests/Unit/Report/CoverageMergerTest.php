<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Report;

use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Report\CoverageMerger;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * Deliverable 4 (unit): two small serialized `CodeCoverage` objects merged into one, and the
 * resulting line counts checked directly — no PHPUnit process, no fixture project.
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

        $run = self::buildCoverage(
            [$fileA => [1 => null, 2 => [], 3 => ['T1'], 4 => ['T1']]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.01]],
        );
        $runPath = $this->dir . '/coverage.php';
        self::writeRunCoveragePhp($run, $runPath);

        $snapshot = self::buildCoverage(
            [$fileB => [9 => null, 10 => ['T2']]],
            ['T2' => ['size' => 'small', 'status' => 'passed', 'time' => 0.02]],
        );
        $snapshotPath = $this->dir . '/snapshot.cov';
        file_put_contents($snapshotPath, serialize($snapshot));

        $outputPath = $this->dir . '/merged.php';

        self::assertTrue(CoverageMerger::merge($runPath, [$snapshotPath], $outputPath));
        self::assertFileExists($outputPath);

        $merged = include $outputPath;
        self::assertInstanceOf(CodeCoverage::class, $merged);

        $lineCoverage = $merged->getData(true)->lineCoverage();

        self::assertSame(['T1'], $lineCoverage[$fileA][3]);
        self::assertSame(['T1'], $lineCoverage[$fileA][4]);
        self::assertSame(['T2'], $lineCoverage[$fileB][10]);
        self::assertNull($lineCoverage[$fileA][1]);
        self::assertSame([], $lineCoverage[$fileA][2]);

        self::assertArrayHasKey('T1', $merged->getTests());
        self::assertArrayHasKey('T2', $merged->getTests());
    }

    #[Test]
    public function merge_unions_hits_on_the_same_line_from_two_different_tests(): void
    {
        $file = $this->dir . '/src/Shared.php';

        $run = self::buildCoverage(
            [$file => [5 => ['T1']]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.01]],
        );
        $runPath = $this->dir . '/coverage.php';
        self::writeRunCoveragePhp($run, $runPath);

        $snapshot = self::buildCoverage(
            [$file => [5 => ['T2']]],
            ['T2' => ['size' => 'small', 'status' => 'passed', 'time' => 0.02]],
        );
        $snapshotPath = $this->dir . '/snapshot.cov';
        file_put_contents($snapshotPath, serialize($snapshot));

        $outputPath = $this->dir . '/merged.php';
        self::assertTrue(CoverageMerger::merge($runPath, [$snapshotPath], $outputPath));

        $merged = include $outputPath;
        self::assertInstanceOf(CodeCoverage::class, $merged);

        $line5 = $merged->getData(true)->lineCoverage()[$file][5];
        self::assertIsArray($line5);
        sort($line5);
        self::assertSame(['T1', 'T2'], $line5);
    }

    #[Test]
    public function merge_returns_false_when_the_run_coverage_file_is_missing(): void
    {
        self::assertFalse(CoverageMerger::merge($this->dir . '/missing.php', [], $this->dir . '/out.php'));
    }

    #[Test]
    public function merge_ignores_a_missing_or_corrupt_snapshot(): void
    {
        $run = self::buildCoverage(
            [$this->dir . '/src/A.php' => [1 => ['T1']]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );
        $runPath = $this->dir . '/coverage.php';
        self::writeRunCoveragePhp($run, $runPath);

        $corrupt = $this->dir . '/corrupt.cov';
        file_put_contents($corrupt, 'not a serialized object');

        $outputPath = $this->dir . '/merged.php';
        $ok = CoverageMerger::merge($runPath, [$this->dir . '/does-not-exist.cov', $corrupt], $outputPath);

        self::assertTrue($ok);
        $merged = include $outputPath;
        self::assertInstanceOf(CodeCoverage::class, $merged);
        self::assertArrayHasKey('T1', $merged->getTests());
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

        $coverage = include $outputPath;
        self::assertInstanceOf(CodeCoverage::class, $coverage);
        self::assertSame([], $coverage->getData(true)->lineCoverage(), 'nothing executed: no line hits of its own');

        // And it is still a valid target for merge(): folding a snapshot in works exactly
        // as it would against a run coverage a real PHPUnit process produced.
        $fileA = $this->dir . '/src/A.php';
        $snapshot = self::buildCoverage(
            [$fileA => [1 => ['T1']]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );
        $snapshotPath = $this->dir . '/snapshot.cov';
        file_put_contents($snapshotPath, serialize($snapshot));

        $mergedPath = $this->dir . '/merged.php';
        self::assertTrue(CoverageMerger::merge($outputPath, [$snapshotPath], $mergedPath));

        $merged = include $mergedPath;
        self::assertInstanceOf(CodeCoverage::class, $merged);
        self::assertSame(['T1'], $merged->getData(true)->lineCoverage()[$fileA][1]);
    }

    /**
     * @param array<string, array<int, null|list<string>>> $lineCoverage
     * @param array<string, array{size: string, status: string, time: float}> $tests
     */
    private static function buildCoverage(array $lineCoverage, array $tests): CodeCoverage
    {
        $filter = new Filter();
        $filter->includeFiles(array_keys($lineCoverage));

        $driver = (new Selector())->forLineCoverage($filter);

        $coverage = new CodeCoverage($driver, $filter);
        $data = new ProcessedCodeCoverageData();
        $data->setLineCoverage($lineCoverage);
        $coverage->setData($data);
        $coverage->setTests($tests);

        return $coverage;
    }

    private static function writeRunCoveragePhp(CodeCoverage $coverage, string $path): void
    {
        $coverage->clearCache();
        $serialized = serialize($coverage);
        $buffer = "<?php\nreturn unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'\n"
            . $serialized . "\nEND_OF_COVERAGE_SERIALIZATION\n);\n";
        file_put_contents($path, $buffer);
    }
}
