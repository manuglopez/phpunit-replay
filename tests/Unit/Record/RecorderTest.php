<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\Recorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecorderTest extends TestCase
{
    #[Test]
    public function it_records_files_that_have_a_line_with_hits_and_ignores_files_with_no_hits(): void
    {
        $driver = new FakeCoverageDriver([
            [
                '/project/src/Used.php' => [10 => 1, 11 => 0],
                '/project/src/Unused.php' => [5 => 0, 6 => 0],
            ],
        ]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => ['/project/src/Used.php']],
            $recorder->perTestFiles(),
        );
    }

    #[Test]
    public function it_excludes_a_file_whose_only_executed_line_is_the_highest_when_other_lines_are_unexecuted(): void
    {
        // Looks like the file was merely autoloaded (only the very last line ran),
        // not actually exercised by the test.
        $driver = new FakeCoverageDriver([
            [
                '/project/src/Autoloaded.php' => [5 => 0, 10 => 0, 20 => 1],
            ],
        ]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    #[Test]
    public function it_includes_a_file_when_the_only_executed_line_is_not_the_highest_numbered_one(): void
    {
        $driver = new FakeCoverageDriver([
            [
                '/project/src/Partial.php' => [5 => 1, 10 => 0, 20 => 0],
            ],
        ]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => ['/project/src/Partial.php']],
            $recorder->perTestFiles(),
        );
    }

    #[Test]
    public function it_includes_a_file_when_every_reported_line_was_executed(): void
    {
        // No unexecuted lines reported at all: the "highest line only" exception does
        // not apply, even though the only covered line happens to be the highest one.
        $driver = new FakeCoverageDriver([
            [
                '/project/src/FullyCovered.php' => [5 => 1],
            ],
        ]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => ['/project/src/FullyCovered.php']],
            $recorder->perTestFiles(),
        );
    }

    #[Test]
    public function it_excludes_a_file_reporting_only_negative_xdebug_style_hit_markers(): void
    {
        $driver = new FakeCoverageDriver([
            [
                '/project/src/Untouched.php' => [1 => -1, 2 => -2],
            ],
        ]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    #[Test]
    public function it_ignores_empty_unknown_and_eval_test_files(): void
    {
        $driver = new FakeCoverageDriver([]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('');
        self::assertNull($recorder->currentTestFile());

        $recorder->beginTest('unknown');
        self::assertNull($recorder->currentTestFile());

        $recorder->beginTest("/tmp/eval()'d code(3) : eval()'d code");
        self::assertNull($recorder->currentTestFile());

        self::assertSame(0, $driver->startCalls);
    }

    #[Test]
    public function begin_test_flushes_a_still_open_previous_test_before_starting_the_next(): void
    {
        // Mirrors PHPUnit: a test whose setUp() throws Skipped/IncompleteTest never
        // becomes wasPrepared(), so Test\Finished (and therefore endTest()) never
        // arrives for it. The next test's PreparationStarted must not silently drop
        // the dangling coverage session — it has to close it first.
        $driver = new FakeCoverageDriver([
            ['/project/src/A.php' => [1 => 1]],
            ['/project/src/B.php' => [1 => 1]],
        ]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/ATest.php');
        // No endTest() call here.
        $recorder->beginTest('/project/tests/BTest.php');

        self::assertSame('/project/tests/BTest.php', $recorder->currentTestFile());
        self::assertSame(1, $driver->stopCalls);
        self::assertSame(2, $driver->startCalls);

        $recorder->endTest();

        self::assertSame(2, $driver->stopCalls);
        self::assertSame(
            [
                '/project/tests/ATest.php' => ['/project/src/A.php'],
                '/project/tests/BTest.php' => ['/project/src/B.php'],
            ],
            $recorder->perTestFiles(),
        );
    }

    #[Test]
    public function link_source_and_link_table_are_ignored_outside_an_open_test(): void
    {
        $driver = new FakeCoverageDriver([]);
        $recorder = new Recorder($driver);

        $recorder->linkSource('/project/resources/views/welcome.blade.php');
        $recorder->linkTable('orders');

        self::assertSame([], $recorder->perTestFiles());
        self::assertSame([], $recorder->perTestTables());
    }

    #[Test]
    public function link_source_and_link_table_attach_to_the_currently_open_test(): void
    {
        $driver = new FakeCoverageDriver([[]]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->linkSource('/project/resources/views/welcome.blade.php');
        $recorder->linkTable('Orders');
        $recorder->linkTable('users');
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => ['/project/resources/views/welcome.blade.php']],
            $recorder->perTestFiles(),
        );
        self::assertSame(
            ['/project/tests/FooTest.php' => ['orders', 'users']],
            $recorder->perTestTables(),
        );
    }

    #[Test]
    public function reset_clears_all_recorded_state(): void
    {
        $driver = new FakeCoverageDriver([['/project/src/A.php' => [1 => 1]]]);
        $recorder = new Recorder($driver);

        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();
        $recorder->reset();

        self::assertNull($recorder->currentTestFile());
        self::assertSame([], $recorder->perTestFiles());
        self::assertSame([], $recorder->perTestTables());
    }
}
