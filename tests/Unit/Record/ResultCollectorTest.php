<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestStatus\TestStatus;

final class ResultCollectorTest extends TestCase
{
    #[Test]
    public function a_pass_after_an_issue_keeps_the_issue_and_only_refreshes_time(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        $collector->testTriggeredWarning('careful');
        $firstTime = $collector->all()['Foo::bar']['time'];

        usleep(2000);
        $collector->testPassed();

        $result = $collector->all()['Foo::bar'];

        self::assertSame(TestStatus::warning()->asInt(), $result['status']);
        self::assertSame('careful', $result['message']);
        self::assertGreaterThanOrEqual($firstTime, $result['time']);
    }

    #[Test]
    public function a_failure_overrides_a_previously_recorded_warning(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        $collector->testTriggeredWarning('careful');
        $collector->testFailed('boom');

        $result = $collector->all()['Foo::bar'];

        self::assertSame(TestStatus::failure()->asInt(), $result['status']);
        self::assertSame('boom', $result['message']);
    }

    #[Test]
    public function a_deprecation_does_not_downgrade_an_already_recorded_warning(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        $collector->testTriggeredWarning('careful');
        $collector->testTriggeredDeprecation('meh');
        $collector->testPassed();

        $result = $collector->all()['Foo::bar'];

        self::assertSame(TestStatus::warning()->asInt(), $result['status']);
        self::assertSame('careful', $result['message']);
    }

    #[Test]
    public function a_more_severe_issue_upgrades_a_previously_recorded_lesser_issue(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        $collector->testTriggeredNotice('fyi');
        $collector->testTriggeredDeprecation('meh');
        $collector->testPassed();

        $result = $collector->all()['Foo::bar'];

        self::assertSame(TestStatus::deprecation()->asInt(), $result['status']);
        self::assertSame('meh', $result['message']);
    }

    #[Test]
    public function risky_is_recorded(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        $collector->testRisky('no assertions performed');

        $result = $collector->all()['Foo::bar'];

        self::assertSame(TestStatus::risky()->asInt(), $result['status']);
        self::assertSame('no assertions performed', $result['message']);
    }

    #[Test]
    public function assertions_are_recorded_on_finish(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        $collector->testPassed();
        $collector->recordAssertions('Foo::bar', 3);
        $collector->finishTest();

        self::assertSame(3, $collector->all()['Foo::bar']['assertions']);
    }

    #[Test]
    public function record_assertions_is_a_no_op_for_a_test_id_without_a_recorded_result(): void
    {
        $collector = new ResultCollector();

        $collector->recordAssertions('Unknown::test', 5);

        self::assertSame([], $collector->all());
    }

    #[Test]
    public function has_unfinished_test_is_true_until_a_result_is_recorded(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        self::assertTrue($collector->hasUnfinishedTest());

        $collector->testPassed();
        self::assertFalse($collector->hasUnfinishedTest());
    }

    #[Test]
    public function a_skipped_result_survives_when_the_next_test_is_prepared_without_the_previous_one_finishing(): void
    {
        // Mirrors PHPUnit: TestCase::runBare() only emits Test\Finished when the test
        // wasPrepared(), and a setUp() that throws SkippedTest/IncompleteTest never
        // reaches that point. Test\Skipped still fires, so testSkipped() records a
        // complete result for A before B's PreparationStarted overwrites the "current"
        // test pointers — A's already-recorded entry must not be touched by that.
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::a', '/project/tests/FooTest.php');
        $collector->testSkipped('skip me');

        $collector->testPrepared('Foo::b', '/project/tests/FooTest.php');

        $resultA = $collector->all()['Foo::a'];
        self::assertSame(TestStatus::skipped()->asInt(), $resultA['status']);
        self::assertSame('skip me', $resultA['message']);

        self::assertTrue($collector->hasUnfinishedTest());

        $collector->testPassed();
        $collector->finishTest();

        $resultB = $collector->all()['Foo::b'];
        self::assertSame(TestStatus::success()->asInt(), $resultB['status']);

        // A is untouched by B's lifecycle.
        self::assertSame($resultA, $collector->all()['Foo::a']);
    }

    #[Test]
    public function reset_clears_all_recorded_state(): void
    {
        $collector = new ResultCollector();

        $collector->testPrepared('Foo::bar');
        $collector->testPassed();
        $collector->reset();

        self::assertSame([], $collector->all());
        self::assertFalse($collector->hasUnfinishedTest());
    }
}
