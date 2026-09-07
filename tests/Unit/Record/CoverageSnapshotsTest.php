<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\CoverageSnapshots;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

final class CoverageSnapshotsTest extends TestCase
{
    private string $projectRoot;

    private string $stateDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = TempDir::make('coverage-snapshots-root');
        $this->stateDir = TempDir::make('coverage-snapshots-state');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->projectRoot);
        TempDir::remove($this->stateDir);

        parent::tearDown();
    }

    #[Test]
    public function capture_writes_one_snapshot_per_test_file_restricted_to_its_own_test_ids(): void
    {
        $testFileA = $this->writeTestFile('tests/ATest.php', 'A');
        $testFileB = $this->writeTestFile('tests/BTest.php', 'B');

        $sourceA = $this->projectRoot . '/src/Shared.php';

        $coverage = self::buildCoverage(
            [$sourceA => [1 => null, 2 => [], 3 => ['A::a'], 4 => ['B::b'], 5 => ['A::a', 'B::b']]],
            [
                'A::a' => ['size' => 'small', 'status' => 'passed', 'time' => 0.01],
                'B::b' => ['size' => 'small', 'status' => 'passed', 'time' => 0.02],
            ],
        );

        $results = [
            'A::a' => ['status' => 0, 'message' => '', 'time' => 0.01, 'assertions' => 1, 'file' => $testFileA],
            'B::b' => ['status' => 0, 'message' => '', 'time' => 0.02, 'assertions' => 1, 'file' => $testFileB],
        ];

        $snapshots = new CoverageSnapshots($this->stateDir);
        $map = $snapshots->capture($results, $coverage, $this->projectRoot);

        self::assertSame(['tests/ATest.php', 'tests/BTest.php'], array_keys($map));

        $keyA = $map['tests/ATest.php'];
        $keyB = $map['tests/BTest.php'];
        self::assertNotSame($keyA, $keyB);

        self::assertFileExists($snapshots->path($keyA));
        self::assertFileExists($snapshots->path($keyB));

        $snapshotA = unserialize((string) file_get_contents($snapshots->path($keyA)));
        self::assertInstanceOf(CodeCoverage::class, $snapshotA);
        $linesA = $snapshotA->getData(true)->lineCoverage()[$sourceA];

        self::assertNull($linesA[1]);
        self::assertSame([], $linesA[2]);
        self::assertSame(['A::a'], $linesA[3]);
        self::assertSame([], $linesA[4]);
        self::assertSame(['A::a'], $linesA[5]);
        self::assertArrayHasKey('A::a', $snapshotA->getTests());
        self::assertArrayNotHasKey('B::b', $snapshotA->getTests());

        $snapshotB = unserialize((string) file_get_contents($snapshots->path($keyB)));
        self::assertInstanceOf(CodeCoverage::class, $snapshotB);
        $linesB = $snapshotB->getData(true)->lineCoverage()[$sourceA];

        self::assertSame([], $linesB[3]);
        self::assertSame(['B::b'], $linesB[4]);
        self::assertSame(['B::b'], $linesB[5]);
    }

    #[Test]
    public function capture_skips_a_test_file_that_touched_nothing(): void
    {
        $testFile = $this->writeTestFile('tests/EmptyTest.php', 'Empty');
        $source = $this->projectRoot . '/src/Untouched.php';

        $coverage = self::buildCoverage(
            [$source => [1 => null]],
            ['Empty::e' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $results = [
            'Empty::e' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => $testFile],
        ];

        $map = (new CoverageSnapshots($this->stateDir))->capture($results, $coverage, $this->projectRoot);

        self::assertSame([], $map);
    }

    #[Test]
    public function capture_returns_empty_when_no_result_carries_a_file(): void
    {
        $coverage = self::buildCoverage([], []);
        $results = ['A::a' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 0]];

        $map = (new CoverageSnapshots($this->stateDir))->capture($results, $coverage, $this->projectRoot);

        self::assertSame([], $map);
    }

    private function writeTestFile(string $relative, string $marker): string
    {
        $absolute = $this->projectRoot . '/' . $relative;
        // ContentHash strips comments/whitespace from .php content before hashing, so the
        // marker must be actual code (a class name), not a comment, for two test files to
        // hash differently.
        TempDir::write($absolute, "<?php\nfinal class " . $marker . "Marker {}\n");

        return $absolute;
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
}
