<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Coverage;

use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Coverage\LineHits;
use Manuglopez\Replay\Coverage\Snapshot;
use Manuglopez\Replay\Tests\Support\CoverageFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * The shape-independent `<stateDir>/coverage/<k>.cov` payload: restricted from a run's
 * coverage, JSON on disk, and rebuilt into whatever the installed php-code-coverage
 * understands. Every fixture here is built through {@see CoverageFixture::native()}, so on
 * php-code-coverage 14.3 these tests really are walking interned test indexes and hit counts,
 * not a hand-written array in the shape 13 used.
 */
final class SnapshotTest extends TestCase
{
    private const FILE = '/project/src/Shared.php';

    #[Test]
    public function it_keeps_only_the_wanted_test_ids_and_survives_a_json_round_trip(): void
    {
        $snapshot = $this->restrict(['A::a']);
        self::assertNotNull($snapshot);

        $encoded = $snapshot->encode();
        self::assertIsString($encoded);

        $decoded = Snapshot::decode($encoded);
        self::assertNotNull($decoded);

        $lines = CoverageFixture::neutral($decoded->toCoverage(false))[self::FILE];

        self::assertNull($lines[1], 'a line that is not executable stays not executable');
        self::assertSame([], $lines[2], 'a line nobody executed stays known-but-unhit');
        self::assertSame(['A::a'], $lines[3]);
        self::assertSame([], $lines[4], 'B::b\'s own line is emptied, not dropped');
        self::assertSame(['A::a'], $lines[5], 'a shared line keeps only this test file\'s hit');
    }

    #[Test]
    public function it_keeps_only_the_wanted_tests_metadata(): void
    {
        $snapshot = $this->restrict(['B::b']);
        self::assertNotNull($snapshot);

        $tests = $snapshot->toCoverage(false)->getTests();

        self::assertSame(['B::b'], array_keys($tests));
        self::assertSame('passed', $tests['B::b']['status']);
    }

    #[Test]
    public function it_is_null_for_a_test_file_that_touched_nothing(): void
    {
        $coverage = CoverageFixture::native(
            ['A::a' => [self::FILE => [1 => CoverageFixture::DEAD, 2 => CoverageFixture::MISSED]]],
            ['A::a' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        self::assertNull($this->restrictOf($coverage, ['A::a']));
    }

    /**
     * The point of the whole abstraction: what comes out of a snapshot has to be something the
     * INSTALLED php-code-coverage will merge, whichever internal representation that is.
     */
    #[Test]
    public function what_it_rebuilds_merges_into_a_run_coverage(): void
    {
        $other = '/project/src/Other.php';

        $run = CoverageFixture::native(
            ['T1' => [self::FILE => [7 => CoverageFixture::HIT]]],
            ['T1' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $snapshot = $this->restrictOf(
            CoverageFixture::native(
                ['T2' => [$other => [9 => CoverageFixture::HIT], self::FILE => [7 => CoverageFixture::HIT]]],
                ['T2' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
            ),
            ['T2'],
        );
        self::assertNotNull($snapshot);

        $run->merge($snapshot->toCoverage(false));

        $lines = CoverageFixture::neutral($run);

        self::assertSame(['T1', 'T2'], $lines[self::FILE][7], 'both tests are credited with the shared line');
        self::assertSame(['T2'], $lines[$other][9], 'a file only the snapshot knows about is added');
        self::assertSame(['T1', 'T2'], array_keys($run->getTests()));
    }

    /**
     * php-code-coverage 14.3 interns test ids: a snapshot rebuilt there must carry an index
     * table, or `CodeCoverage::merge()` remaps indexes that mean nothing and credits the wrong
     * tests. Before 14.3 the ids sit inline and there is no table to carry.
     */
    #[Test]
    public function it_rebuilds_in_the_installed_representation(): void
    {
        $snapshot = $this->restrict(['A::a']);
        self::assertNotNull($snapshot);

        $data = $snapshot->toCoverage(false)->getData(true);
        $hit = $data->lineCoverage()[self::FILE][3];
        self::assertIsArray($hit);

        if (! CoverageFormat::usesTestIndexes()) {
            self::assertSame(['A::a'], $hit, 'php-code-coverage 11-14.2 stores the ids inline');
            self::assertNull(LineHits::testIds($data));

            return;
        }

        self::assertSame([0 => 1], $hit, 'php-code-coverage 14.3 stores test index => hit count');
        self::assertSame([0 => 'A::a'], LineHits::testIds($data));
    }

    /**
     * `ProcessedCodeCoverageData::merge()` combines the "these are exact execution counts"
     * flag with `&&`, so a snapshot that always claimed `false` would flatten a whole xdebug
     * run's per-line counts down to "executed at least once" the moment one replayed test file
     * was folded in.
     */
    #[Test]
    public function it_agrees_with_the_run_about_hit_counts(): void
    {
        $snapshot = $this->restrict(['A::a']);
        self::assertNotNull($snapshot);

        self::assertSame(
            CoverageFormat::usesTestIndexes(),
            CoverageFormat::collectsHitCounts($snapshot->toCoverage(true)->getData(true)),
        );
        self::assertFalse(CoverageFormat::collectsHitCounts($snapshot->toCoverage(false)->getData(true)));
    }

    #[Test]
    public function hit_counts_survive_the_round_trip_where_they_exist(): void
    {
        if (! CoverageFormat::usesTestIndexes()) {
            self::markTestSkipped('the installed php-code-coverage does not record hit counts');
        }

        $coverage = CoverageFixture::native(
            ['A::a' => [self::FILE => [3 => 5]]],
            ['A::a' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $snapshot = $this->restrictOf($coverage, ['A::a']);
        self::assertNotNull($snapshot);

        $encoded = $snapshot->encode();
        self::assertIsString($encoded);

        $decoded = Snapshot::decode($encoded);
        self::assertNotNull($decoded);

        self::assertSame(
            [0 => 5],
            $decoded->toCoverage(true)->getData(true)->lineCoverage()[self::FILE][3],
        );
    }

    #[Test]
    public function decode_rejects_anything_that_is_not_a_snapshot_of_this_format(): void
    {
        self::assertNull(Snapshot::decode('not json'));
        self::assertNull(Snapshot::decode('{}'));
        self::assertNull(Snapshot::decode('[]'));
        self::assertNull(Snapshot::decode('{"format":1,"lines":{},"tests":{}}'), 'an older snapshot shape');
        self::assertNull(Snapshot::decode('{"format":' . CoverageFormat::SNAPSHOT_FORMAT . ',"lines":"nope","tests":{}}'));
        self::assertNull(Snapshot::decode('{"format":' . CoverageFormat::SNAPSHOT_FORMAT . ',"lines":{},"tests":{}}'), 'nothing recorded');
    }

    /**
     * The `.cov` files of releases up to 0.1.0 were a raw `serialize()` of a php-code-coverage
     * `CodeCoverage` object. `Cache\Fingerprint` clears the cached results across that
     * boundary, but a stray file must still be refused rather than half-read.
     */
    #[Test]
    public function decode_rejects_a_serialized_code_coverage_object_from_an_older_release(): void
    {
        $legacy = serialize(CoverageFixture::native(
            ['A::a' => [self::FILE => [3 => CoverageFixture::HIT]]],
            ['A::a' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        ));

        self::assertNull(Snapshot::decode($legacy));
    }

    #[Test]
    public function decode_drops_line_entries_it_cannot_make_sense_of(): void
    {
        $decoded = Snapshot::decode(
            '{"format":' . CoverageFormat::SNAPSHOT_FORMAT . ',"lines":{"' . self::FILE . '":'
            . '{"3":{"A::a":1},"4":{"":2,"B::b":"x","C::c":0},"5":"nope","6":null}},"tests":{"A::a":{"size":"small","status":"passed","time":0.0}}}',
        );

        self::assertNotNull($decoded);

        $lines = CoverageFixture::neutral($decoded->toCoverage(false))[self::FILE];

        self::assertSame(['A::a'], $lines[3]);
        self::assertSame([], $lines[4], 'an empty id, a non-int count and a zero count are all dropped');
        self::assertArrayNotHasKey(5, $lines, 'a line whose value is not a map is dropped entirely');
        self::assertNull($lines[6]);
    }

    /** @param list<string> $wantedIds */
    private function restrict(array $wantedIds): ?Snapshot
    {
        return $this->restrictOf($this->twoTests(), $wantedIds);
    }

    /** @param list<string> $wantedIds */
    private function restrictOf(CodeCoverage $coverage, array $wantedIds): ?Snapshot
    {
        $data = $coverage->getData(true);

        return Snapshot::restrict($data->lineCoverage(), LineHits::testIds($data), $coverage->getTests(), $wantedIds);
    }

    private function twoTests(): CodeCoverage
    {
        return CoverageFixture::native(
            [
                'A::a' => [self::FILE => [
                    1 => CoverageFixture::DEAD,
                    2 => CoverageFixture::MISSED,
                    3 => CoverageFixture::HIT,
                    4 => CoverageFixture::MISSED,
                    5 => CoverageFixture::HIT,
                ]],
                'B::b' => [self::FILE => [
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
    }
}
