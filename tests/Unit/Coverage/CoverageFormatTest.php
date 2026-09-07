<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Coverage;

use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Coverage\LegacyArchive;
use Manuglopez\Replay\Coverage\SerializedArchive;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

final class CoverageFormatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = TempDir::make('coverage-format');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->dir);

        parent::tearDown();
    }

    #[Test]
    public function the_archive_matches_whether_the_installed_php_code_coverage_has_a_serializer(): void
    {
        $expected = class_exists('SebastianBergmann\CodeCoverage\Serialization\Serializer')
            ? SerializedArchive::class
            : LegacyArchive::class;

        self::assertInstanceOf($expected, CoverageFormat::archive());
    }

    #[Test]
    public function the_serialization_format_is_the_one_the_installed_php_code_coverage_declares(): void
    {
        $format = CoverageFormat::serializationFormat();

        if (! class_exists('SebastianBergmann\CodeCoverage\Serialization\Serializer')) {
            self::assertNull($format, 'php-code-coverage 11-13 has no format marker at all');

            return;
        }

        self::assertIsInt($format);
        self::assertGreaterThanOrEqual(1, $format);
    }

    #[Test]
    public function declared_format_reads_the_marker_off_the_first_line(): void
    {
        $path = $this->write('marked.php', "<?php // phpunit/php-code-coverage serialization format 7\nreturn [];\n");

        self::assertSame(7, CoverageFormat::declaredFormat($path));
    }

    #[Test]
    public function declared_format_is_null_for_a_pre_14_file_and_for_a_missing_one(): void
    {
        $path = $this->write('legacy.php', "<?php\nreturn unserialize(<<<'END'\nb:1;\nEND\n);\n");

        self::assertNull(CoverageFormat::declaredFormat($path));
        self::assertNull(CoverageFormat::declaredFormat($this->dir . '/nope.php'));
    }

    /**
     * php-code-coverage 14.0 and 14.1 stamped their own exact version on the first line and
     * refused any other; 14.2 replaced that with a format number. Both markers therefore have
     * to be recognised, or a 14.0 file looks indistinguishable from a pre-14 one — which
     * php-code-coverage 14.0 itself can read and 13 cannot.
     */
    #[Test]
    public function the_marker_recognises_both_forms_php_code_coverage_14_has_used(): void
    {
        self::assertSame(
            'fmt3',
            CoverageFormat::markerOf($this->write('numbered.php', "<?php // phpunit/php-code-coverage serialization format 3\nreturn [];\n")),
        );

        self::assertSame(
            'ver14.0.2',
            CoverageFormat::markerOf($this->write('versioned.php', "<?php // phpunit/php-code-coverage version 14.0.2\nreturn [];\n")),
        );

        self::assertNull(CoverageFormat::markerOf($this->write('unmarked.php', "<?php\nreturn null;\n")));
        self::assertNull(CoverageFormat::markerOf($this->write('empty.php', '')));
        self::assertNull(CoverageFormat::markerOf($this->dir . '/nope.php'));
    }

    #[Test]
    public function the_own_marker_is_the_one_the_installed_php_code_coverage_writes(): void
    {
        $format = CoverageFormat::serializationFormat();
        $marker = CoverageFormat::ownMarker();

        if ($format !== null) {
            self::assertSame('fmt' . $format, $marker);

            return;
        }

        if (class_exists('SebastianBergmann\CodeCoverage\Serialization\Serializer')) {
            self::assertIsString($marker);
            self::assertStringStartsWith('ver', $marker, 'php-code-coverage 14.0/14.1 stamps its own version');

            return;
        }

        self::assertNull($marker, 'php-code-coverage 11-13 writes no marker at all');
    }

    /**
     * Every marker form other than this installation's own is foreign, in both directions.
     */
    #[Test]
    public function every_other_marker_form_is_foreign(): void
    {
        $own = CoverageFormat::ownMarker();

        $candidates = [
            'unmarked.php' => "<?php\nreturn null;\n",
            'numbered.php' => "<?php // phpunit/php-code-coverage serialization format 1\nreturn [];\n",
            'versioned.php' => "<?php // phpunit/php-code-coverage version 14.0.2\nreturn [];\n",
        ];

        foreach ($candidates as $name => $body) {
            $path = $this->write($name, $body);

            self::assertSame(
                CoverageFormat::markerOf($path) !== $own,
                CoverageFormat::isForeign($path),
                $name,
            );
        }
    }

    /**
     * The defensive gate {@see \Manuglopez\Replay\Report\CoverageMerger::merge()} degrades on:
     * a `--coverage-php` file whose format is not the one the installed php-code-coverage
     * reads is unreadable in BOTH directions, and a format number that merely moved (14.2
     * wrote 1, 14.3 writes 3) is just as foreign as a missing marker.
     */
    #[Test]
    public function a_file_from_another_format_is_foreign_and_one_from_this_format_is_not(): void
    {
        $installed = CoverageFormat::serializationFormat();

        $marked = $this->write('marked.php', "<?php // phpunit/php-code-coverage serialization format 99\nreturn [];\n");
        $unmarked = $this->write('legacy.php', "<?php\nreturn null;\n");

        self::assertTrue(CoverageFormat::isForeign($marked), 'format 99 is nobody\'s format');

        if (CoverageFormat::ownMarker() === null) {
            self::assertFalse(CoverageFormat::isForeign($unmarked), 'an unmarked file IS the pre-14 format');

            return;
        }

        self::assertTrue(CoverageFormat::isForeign($unmarked), 'php-code-coverage 14+ cannot read an unmarked file');

        if ($installed !== null) {
            $own = $this->write('own.php', '<?php // phpunit/php-code-coverage serialization format ' . $installed . "\nreturn [];\n");
            self::assertFalse(CoverageFormat::isForeign($own));
        }
    }

    #[Test]
    public function the_id_names_the_installed_major_the_format_and_the_snapshot_shape(): void
    {
        $id = CoverageFormat::id();

        self::assertMatchesRegularExpression('#^cc(\d+|\?)/(php|ser|fmt\d+)/snap' . CoverageFormat::SNAPSHOT_FORMAT . '$#', $id);
        self::assertSame($id, CoverageFormat::id(), 'stable within a process');
    }

    /**
     * Cheap, but it is the assertion that makes the `environmental` fingerprint key mean
     * something: the token has to change when the installed php-code-coverage changes the way
     * it writes coverage, and this pins the two things it is derived from.
     */
    #[Test]
    public function the_id_carries_the_serialization_format_it_reports(): void
    {
        $format = CoverageFormat::serializationFormat();

        if ($format !== null) {
            self::assertStringContainsString('/fmt' . $format . '/', CoverageFormat::id());

            return;
        }

        self::assertStringContainsString(
            class_exists('SebastianBergmann\CodeCoverage\Serialization\Serializer') ? '/ser/' : '/php/',
            CoverageFormat::id(),
        );
    }

    #[Test]
    public function new_processed_data_records_hit_counts_only_where_the_installed_version_can(): void
    {
        $data = CoverageFormat::newProcessedData(true);

        self::assertInstanceOf(ProcessedCodeCoverageData::class, $data);
        self::assertSame(CoverageFormat::usesTestIndexes(), CoverageFormat::collectsHitCounts($data));

        self::assertFalse(CoverageFormat::collectsHitCounts(CoverageFormat::newProcessedData(false)));
    }

    #[Test]
    public function tests_from_keeps_only_non_empty_string_ids_mapping_to_arrays(): void
    {
        $out = CoverageFormat::testsFrom([
            'A::a' => ['size' => 'small', 'status' => 'passed', 'time' => 0.5],
            '' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0],
            7 => ['size' => 'small', 'status' => 'passed', 'time' => 0.0],
            'B::b' => 'not an array',
        ]);

        self::assertSame(['A::a'], array_keys($out));
        self::assertSame(['size' => 'small', 'status' => 'passed', 'time' => 0.5], $out['A::a']);
    }

    private function write(string $name, string $content): string
    {
        $path = $this->dir . '/' . $name;
        TempDir::write($path, $content);

        return $path;
    }
}
