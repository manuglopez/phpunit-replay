<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\PiggybackCoverageDriver;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Tests\Support\CoverageFixture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * The driver `ReplayExtension` uses for the whole dependency graph whenever the user asked
 * PHPUnit for a `--coverage-*` report: it never touches pcov/xdebug itself, it reads the lines
 * PHPUnit's own collector already recorded for the test that just finished.
 *
 * Which makes it the most dangerous place in the package for a php-code-coverage shape change,
 * and the reason this test exists. Reading the per-line hit maps as `list<test id string>` —
 * the shape 11 through 14.2 use — returns nothing at all on 14.3, where the ids are interned
 * behind an index table. No exception, no warning: every edge for every test silently
 * disappears, TIA concludes that no test depends on any source file, and the next run replays
 * everything it should have re-executed. {@see CoverageFixture::native()} builds the fixture
 * the way php-code-coverage builds one, so this test fails on 14.3 unless the driver really
 * reads the installed shape.
 */
final class PiggybackCoverageDriverTest extends TestCase
{
    private const SOURCE = '/project/src/Money.php';

    private const OTHER = '/project/src/Cart.php';

    #[Test]
    public function stop_reports_the_lines_the_test_that_just_finished_executed(): void
    {
        $coverage = CoverageFixture::native(
            [
                'Old::old' => [self::SOURCE => [
                    10 => CoverageFixture::HIT,
                    11 => CoverageFixture::MISSED,
                    12 => CoverageFixture::MISSED,
                ]],
                'New::new' => [self::SOURCE => [
                    10 => CoverageFixture::MISSED,
                    11 => CoverageFixture::HIT,
                    12 => CoverageFixture::HIT,
                ]],
            ],
            [
                'Old::old' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0],
                'New::new' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0],
            ],
        );

        // start() remembers which test ids the collector already knew about, so stop() can
        // tell which one is the test that ran in between. Simulated here by starting from a
        // coverage that only has the older test.
        $driver = $this->driver($this->before($coverage, ['Old::old']), $coverage);

        $driver->start();

        self::assertSame([self::SOURCE => [11 => 1, 12 => 1]], $driver->stop());
    }

    #[Test]
    public function stop_only_reports_files_inside_the_source_scope(): void
    {
        $coverage = CoverageFixture::native(
            ['New::new' => [
                self::SOURCE => [10 => CoverageFixture::HIT],
                '/elsewhere/vendor/Thing.php' => [1 => CoverageFixture::HIT],
            ]],
            ['New::new' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $driver = $this->driver($this->before($coverage, []), $coverage);

        $driver->start();

        self::assertSame([self::SOURCE => [10 => 1]], $driver->stop());
    }

    #[Test]
    public function stop_reports_nothing_when_phpunit_appended_no_new_test(): void
    {
        $coverage = CoverageFixture::native(
            ['Old::old' => [self::SOURCE => [10 => CoverageFixture::HIT]]],
            ['Old::old' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0]],
        );

        $driver = $this->driver($coverage, $coverage);

        $driver->start();

        self::assertSame([], $driver->stop());
    }

    #[Test]
    public function stop_reports_nothing_when_there_is_no_coverage_at_all(): void
    {
        $driver = new PiggybackCoverageDriver($this->scope(), static fn (): ?CodeCoverage => null);

        $driver->start();

        self::assertSame([], $driver->stop());
        self::assertSame('piggyback', $driver->name());
    }

    #[Test]
    public function a_line_hit_by_two_tests_is_still_attributed_to_the_new_one(): void
    {
        $coverage = CoverageFixture::native(
            [
                'Old::old' => [self::SOURCE => [10 => CoverageFixture::HIT], self::OTHER => [1 => CoverageFixture::HIT]],
                'New::new' => [self::SOURCE => [10 => CoverageFixture::HIT]],
            ],
            [
                'Old::old' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0],
                'New::new' => ['size' => 'small', 'status' => 'passed', 'time' => 0.0],
            ],
        );

        $driver = $this->driver($this->before($coverage, ['Old::old']), $coverage);

        $driver->start();

        self::assertSame(
            [self::SOURCE => [10 => 1]],
            $driver->stop(),
            'the shared line belongs to the new test too; the file only the old test touched does not',
        );
    }

    /**
     * A `CodeCoverage` carrying the same data but only `$keep`'s test ids in `getTests()`,
     * standing in for "what the collector looked like before the current test ran".
     *
     * @param list<string> $keep
     */
    private function before(CodeCoverage $coverage, array $keep): CodeCoverage
    {
        $clone = clone $coverage;
        $clone->setTests(array_intersect_key($coverage->getTests(), array_fill_keys($keep, true)));

        return $clone;
    }

    private function driver(CodeCoverage $before, CodeCoverage $after): PiggybackCoverageDriver
    {
        $calls = 0;

        return new PiggybackCoverageDriver(
            $this->scope(),
            static function () use ($before, $after, &$calls): CodeCoverage {
                $calls++;

                return $calls === 1 ? $before : $after;
            },
        );
    }

    private function scope(): SourceScope
    {
        return new SourceScope(['/project/src'], []);
    }
}
