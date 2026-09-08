<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Coverage;

use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Tests\Support\CoverageFixture;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `--coverage-php` reader/writer pair, against whichever php-code-coverage is installed.
 * These tests never name a format: they write with the archive, read with the archive, and
 * assert on what came back — which is exactly the contract
 * {@see \Manuglopez\Replay\Report\CoverageMerger} depends on across four majors.
 */
final class CoverageArchiveTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = TempDir::make('coverage-archive');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function a_round_trip_preserves_the_line_data_and_the_tests(): void
    {
        $fileA = $this->dir . '/src/A.php';
        $fileB = $this->dir . '/src/B.php';

        $written = CoverageFixture::native(
            [
                'T1' => [$fileA => [1 => CoverageFixture::DEAD, 2 => CoverageFixture::MISSED, 3 => CoverageFixture::HIT]],
                'T2' => [$fileB => [9 => CoverageFixture::HIT]],
            ],
            [
                'T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.01],
                'T2' => ['size' => 'small', 'status' => 'passed', 'time' => 0.02],
            ],
        );

        $path = $this->dir . '/coverage.php';
        self::assertTrue(CoverageFormat::archive()->write($path, $written));
        self::assertFileExists($path);

        $read = CoverageFormat::archive()->read($path);
        self::assertNotNull($read);

        $lines = CoverageFixture::neutral($read);

        self::assertNull($lines[$fileA][1]);
        self::assertSame([], $lines[$fileA][2]);
        self::assertSame(['T1'], $lines[$fileA][3]);
        self::assertSame(['T2'], $lines[$fileB][9]);

        self::assertSame(['T1', 'T2'], array_keys($read->getTests()));
    }

    /**
     * php-code-coverage 14 cuts the longest common directory prefix off every covered file and
     * stores it once as `basePath` (upstream issue #925), so a file that PHPUnit wrote holds
     * `A.php`, not `/tmp/…/src/A.php`. Everything in this package speaks absolute paths, and
     * `CodeCoverage::merge()` matches by file key — so a snapshot merged into unexpanded
     * relative data would land under a second, parallel entry for the same file instead of the
     * one already there.
     *
     * This test asserts both halves of the answer at once: the bytes on disk really are
     * relative (skipped where the installed major writes absolute paths, so the test can never
     * pass vacuously), and what the archive hands back is absolute regardless.
     *
     * The branch is on {@see CoverageFormat::usesSerializer()}, NOT on whether a serialization
     * format number is declared. php-code-coverage 14.0 reduces paths exactly like 14.3 but
     * declares no number, so the older `serializationFormat() === null` branch sent it down the
     * "php-code-coverage 11-13 stores absolute paths" path and failed — a wrong assumption in
     * this test, not a defect in the archive, which re-expands unconditionally.
     */
    #[Test]
    public function a_relative_path_written_by_php_code_coverage_14_comes_back_absolute(): void
    {
        $fileA = $this->dir . '/src/A.php';
        $fileB = $this->dir . '/src/B.php';

        $coverage = CoverageFixture::native(
            [
                'T1' => [$fileA => [3 => CoverageFixture::HIT], $fileB => [4 => CoverageFixture::HIT]],
            ],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $path = $this->dir . '/coverage.php';
        self::assertTrue(CoverageFormat::archive()->write($path, $coverage));

        $raw = (string) file_get_contents($path);

        if (CoverageFormat::usesSerializer()) {
            self::assertStringNotContainsString($fileA, $raw, 'php-code-coverage 14 stores paths relative to basePath');
            self::assertStringContainsString('basePath', $raw);
        } else {
            self::assertStringContainsString($fileA, $raw, 'php-code-coverage 11-13 stores absolute paths');
        }

        $read = CoverageFormat::archive()->read($path);
        self::assertNotNull($read);

        self::assertSame([$fileA, $fileB], $read->getData(true)->coveredFiles());
        self::assertSame([3], CoverageFixture::executedLines($read, $fileA));
        self::assertSame([4], CoverageFixture::executedLines($read, $fileB));
    }

    /**
     * The merged file has to be indistinguishable from one PHPUnit wrote itself, or the user's
     * next `phpcov`/`--coverage-html` step chokes on it.
     */
    #[Test]
    public function what_the_archive_writes_is_not_foreign_to_the_installed_php_code_coverage(): void
    {
        $coverage = CoverageFixture::native(
            ['T1' => [$this->dir . '/src/A.php' => [1 => CoverageFixture::HIT]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $path = $this->dir . '/coverage.php';
        self::assertTrue(CoverageFormat::archive()->write($path, $coverage));

        self::assertFalse(CoverageFormat::isForeign($path));
    }

    #[Test]
    public function reading_a_file_from_another_format_returns_null_instead_of_throwing(): void
    {
        $archive = CoverageFormat::archive();

        $foreign = $this->dir . '/foreign.php';
        TempDir::write($foreign, "<?php // phpunit/php-code-coverage serialization format 99\nreturn ['nope'];\n");
        self::assertNull($archive->read($foreign));

        $garbage = $this->dir . '/garbage.php';
        TempDir::write($garbage, "not php at all\n");
        self::assertNull($archive->read($garbage));

        self::assertNull($archive->read($this->dir . '/missing.php'));
    }

    #[Test]
    public function writing_creates_missing_parent_directories(): void
    {
        $coverage = CoverageFixture::native(
            ['T1' => [$this->dir . '/src/A.php' => [1 => CoverageFixture::HIT]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $path = $this->dir . '/runs/abc/coverage.php';
        self::assertTrue(CoverageFormat::archive()->write($path, $coverage));
        self::assertFileExists($path);
        self::assertSame([], glob($this->dir . '/runs/abc/*.tmp') ?: [], 'no temp file left behind');
    }
}
