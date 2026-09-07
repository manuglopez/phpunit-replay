<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Coverage\Snapshot;
use Manuglopez\Replay\Record\CoverageSnapshots;
use Manuglopez\Replay\Tests\Support\CoverageFixture;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        $coverage = CoverageFixture::native(
            [
                'A::a' => [$sourceA => [
                    1 => CoverageFixture::DEAD,
                    2 => CoverageFixture::MISSED,
                    3 => CoverageFixture::HIT,
                    4 => CoverageFixture::MISSED,
                    5 => CoverageFixture::HIT,
                ]],
                'B::b' => [$sourceA => [
                    1 => CoverageFixture::DEAD,
                    2 => CoverageFixture::MISSED,
                    3 => CoverageFixture::MISSED,
                    4 => CoverageFixture::HIT,
                    5 => CoverageFixture::HIT,
                ]],
            ],
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

        $snapshotA = $this->read($snapshots->path($keyA));
        $linesA = CoverageFixture::neutral($snapshotA->toCoverage(false))[$sourceA];

        self::assertNull($linesA[1]);
        self::assertSame([], $linesA[2]);
        self::assertSame(['A::a'], $linesA[3]);
        self::assertSame([], $linesA[4]);
        self::assertSame(['A::a'], $linesA[5]);
        self::assertSame(['A::a'], array_keys($snapshotA->toCoverage(false)->getTests()));

        $linesB = CoverageFixture::neutral($this->read($snapshots->path($keyB))->toCoverage(false))[$sourceA];

        self::assertSame([], $linesB[3]);
        self::assertSame(['B::b'], $linesB[4]);
        self::assertSame(['B::b'], $linesB[5]);
    }

    /**
     * The `.cov` files are this package's own format, not php-code-coverage's: a snapshot
     * recorded under one major has to stay readable under the next, which a serialized
     * `CodeCoverage` object never was.
     */
    #[Test]
    public function a_snapshot_on_disk_is_this_packages_own_json_and_not_a_serialized_object(): void
    {
        $testFile = $this->writeTestFile('tests/ATest.php', 'A');

        $coverage = CoverageFixture::native(
            ['A::a' => [$this->projectRoot . '/src/Shared.php' => [3 => CoverageFixture::HIT]]],
            ['A::a' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $snapshots = new CoverageSnapshots($this->stateDir);
        $map = $snapshots->capture(
            ['A::a' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => $testFile]],
            $coverage,
            $this->projectRoot,
        );

        $content = (string) file_get_contents($snapshots->path($map['tests/ATest.php']));

        self::assertJson($content);
        self::assertStringNotContainsString('SebastianBergmann', $content, 'no php-code-coverage class name is baked in');
    }

    #[Test]
    public function capture_skips_a_test_file_that_touched_nothing(): void
    {
        $testFile = $this->writeTestFile('tests/EmptyTest.php', 'Empty');
        $source = $this->projectRoot . '/src/Untouched.php';

        $coverage = CoverageFixture::native(
            ['Empty::e' => [$source => [1 => CoverageFixture::DEAD]]],
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
        $coverage = CoverageFixture::native([], []);
        $results = ['A::a' => ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 0]];

        $map = (new CoverageSnapshots($this->stateDir))->capture($results, $coverage, $this->projectRoot);

        self::assertSame([], $map);
    }

    private function read(string $path): Snapshot
    {
        $snapshot = Snapshot::decode((string) file_get_contents($path));

        self::assertNotNull($snapshot);

        return $snapshot;
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
}
