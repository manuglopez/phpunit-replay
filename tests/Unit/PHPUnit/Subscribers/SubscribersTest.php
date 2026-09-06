<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit\Subscribers;

use Manuglopez\Replay\PHPUnit\Subscribers\CollectResultOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\FlushOnExecutionFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\MarkTruncatedOnExecutionAborted;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordAssertionsOnFinished;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordConsideredRisky;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordDeprecationTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordErrored;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordFailed;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordMarkedIncomplete;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordNoticeTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPassed;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpDeprecationTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpNoticeTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordPhpWarningTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordSkipped;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordWarningTriggered;
use Manuglopez\Replay\PHPUnit\Subscribers\StartRecordingOnPreparationStarted;
use Manuglopez\Replay\PHPUnit\Subscribers\StopRecordingOnFinished;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\ResultCollector;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Record\RunWriter;
use Manuglopez\Replay\Tests\Support\TempDir;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Event\Code\IssueTrigger\IssueTrigger;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\Throwable;
use PHPUnit\Event\Telemetry;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\DeprecationTriggered;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\NoticeTriggered;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PhpDeprecationTriggered;
use PHPUnit\Event\Test\PhpNoticeTriggered;
use PHPUnit\Event\Test\PhpWarningTriggered;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\WarningTriggered;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Event\TestRunner\ExecutionAborted;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestStatus\TestStatus;
use PHPUnit\Metadata\MetadataCollection;

/**
 * Exercises each subscriber against real PHPUnit event/value objects (constructed
 * directly, not via a running test suite), asserting the Recorder/ResultCollector/
 * RunWriter state after notify().
 */
final class SubscribersTest extends TestCase
{
    private function telemetryInfo(): Telemetry\Info
    {
        $snapshot = new Telemetry\Snapshot(
            Telemetry\HRTime::fromSecondsAndNanoseconds(0, 0),
            Telemetry\MemoryUsage::fromBytes(0),
            Telemetry\MemoryUsage::fromBytes(0),
            new Telemetry\GarbageCollectorStatus(0, 0, 0, 0, 0.0, 0.0, 0.0, 0.0, false, false, false, 0),
        );

        return new Telemetry\Info(
            $snapshot,
            Telemetry\Duration::fromSecondsAndNanoseconds(0, 0),
            Telemetry\MemoryUsage::fromBytes(0),
            Telemetry\Duration::fromSecondsAndNanoseconds(0, 0),
            Telemetry\MemoryUsage::fromBytes(0),
        );
    }

    private function testMethod(string $file = '/project/tests/FooTest.php'): TestMethod
    {
        return new TestMethod(
            'Project\Tests\FooTest',
            'test_it_works',
            $file,
            10,
            new TestDox('FooTest', 'test_it_works', 'test_it_works'),
            MetadataCollection::fromArray([]),
            TestDataCollection::fromArray([]),
        );
    }

    private function throwable(string $message = 'boom'): Throwable
    {
        return new Throwable('RuntimeException', $message, 'RuntimeException: ' . $message, '', null);
    }

    #[Test]
    public function start_recording_on_preparation_started_begins_the_test(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([]));
        $subscriber = new StartRecordingOnPreparationStarted($recorder);

        $subscriber->notify(new PreparationStarted($this->telemetryInfo(), $this->testMethod('/p/tests/FooTest.php')));

        self::assertSame('/p/tests/FooTest.php', $recorder->currentTestFile());
    }

    #[Test]
    public function stop_recording_on_finished_ends_the_test(): void
    {
        $driver = new FakeCoverageDriver([['/p/src/Used.php' => [1 => 1]]]);
        $recorder = new Recorder($driver);
        $recorder->beginTest('/p/tests/FooTest.php');

        $subscriber = new StopRecordingOnFinished($recorder);
        $subscriber->notify(new Finished($this->telemetryInfo(), $this->testMethod(), 1));

        self::assertNull($recorder->currentTestFile());
        self::assertSame(
            ['/p/tests/FooTest.php' => ['/p/src/Used.php']],
            $recorder->perTestFiles(),
        );
    }

    #[Test]
    public function collect_result_on_preparation_started_records_the_test_id_and_file(): void
    {
        $collector = new ResultCollector();
        $subscriber = new CollectResultOnPreparationStarted($collector);

        $subscriber->notify(new PreparationStarted($this->telemetryInfo(), $this->testMethod('/p/tests/FooTest.php')));

        self::assertTrue($collector->hasUnfinishedTest());
    }

    #[Test]
    public function record_passed_records_success(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordPassed($collector))->notify(new Passed($this->telemetryInfo(), $this->testMethod()));

        self::assertSame(
            TestStatus::success()->asInt(),
            $collector->all()[$this->testMethod()->id()]['status'],
        );
    }

    #[Test]
    public function record_failed_records_failure_with_the_throwable_message(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordFailed($collector))->notify(new Failed($this->telemetryInfo(), $this->testMethod(), $this->throwable('nope'), null));

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::failure()->asInt(), $result['status']);
        self::assertSame('nope', $result['message']);
    }

    #[Test]
    public function record_errored_records_error_with_the_throwable_message(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordErrored($collector))->notify(new Errored($this->telemetryInfo(), $this->testMethod(), $this->throwable('kaboom')));

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::error()->asInt(), $result['status']);
        self::assertSame('kaboom', $result['message']);
    }

    #[Test]
    public function record_skipped_records_skipped_with_the_message(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordSkipped($collector))->notify(new Skipped($this->telemetryInfo(), $this->testMethod(), 'skip me'));

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::skipped()->asInt(), $result['status']);
        self::assertSame('skip me', $result['message']);
    }

    #[Test]
    public function record_marked_incomplete_records_incomplete_with_the_throwable_message(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordMarkedIncomplete($collector))->notify(new MarkedIncomplete($this->telemetryInfo(), $this->testMethod(), $this->throwable('todo')));

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::incomplete()->asInt(), $result['status']);
        self::assertSame('todo', $result['message']);
    }

    #[Test]
    public function record_considered_risky_records_risky_with_the_message(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordConsideredRisky($collector))->notify(new ConsideredRisky($this->telemetryInfo(), $this->testMethod(), 'no assertions'));

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::risky()->asInt(), $result['status']);
        self::assertSame('no assertions', $result['message']);
    }

    #[Test]
    public function record_warning_triggered_records_warning_unless_suppressed(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordWarningTriggered($collector))->notify(
            new WarningTriggered($this->telemetryInfo(), $this->testMethod(), 'careful', '/p/src/Foo.php', 5, false, false),
        );

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::warning()->asInt(), $result['status']);
        self::assertSame('careful', $result['message']);
    }

    #[Test]
    public function record_warning_triggered_ignores_a_suppressed_warning(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordWarningTriggered($collector))->notify(
            new WarningTriggered($this->telemetryInfo(), $this->testMethod(), 'careful', '/p/src/Foo.php', 5, true, false),
        );

        self::assertArrayNotHasKey($this->testMethod()->id(), $collector->all());
    }

    #[Test]
    public function record_php_warning_triggered_records_warning(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordPhpWarningTriggered($collector))->notify(
            new PhpWarningTriggered($this->telemetryInfo(), $this->testMethod(), 'careful', '/p/src/Foo.php', 5, false, false),
        );

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::warning()->asInt(), $result['status']);
    }

    #[Test]
    public function record_notice_triggered_records_notice(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordNoticeTriggered($collector))->notify(
            new NoticeTriggered($this->telemetryInfo(), $this->testMethod(), 'fyi', '/p/src/Foo.php', 5, false, false),
        );

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::notice()->asInt(), $result['status']);
    }

    #[Test]
    public function record_php_notice_triggered_records_notice(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordPhpNoticeTriggered($collector))->notify(
            new PhpNoticeTriggered($this->telemetryInfo(), $this->testMethod(), 'fyi', '/p/src/Foo.php', 5, false, false),
        );

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::notice()->asInt(), $result['status']);
    }

    #[Test]
    public function record_deprecation_triggered_records_deprecation(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordDeprecationTriggered($collector))->notify(
            new DeprecationTriggered(
                $this->telemetryInfo(),
                $this->testMethod(),
                'meh',
                '/p/src/Foo.php',
                5,
                false,
                false,
                false,
                IssueTrigger::from(null, null),
                '',
            ),
        );

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::deprecation()->asInt(), $result['status']);
    }

    #[Test]
    public function record_php_deprecation_triggered_records_deprecation(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());

        (new RecordPhpDeprecationTriggered($collector))->notify(
            new PhpDeprecationTriggered(
                $this->telemetryInfo(),
                $this->testMethod(),
                'meh',
                '/p/src/Foo.php',
                5,
                false,
                false,
                false,
                IssueTrigger::from(null, null),
            ),
        );

        $result = $collector->all()[$this->testMethod()->id()];
        self::assertSame(TestStatus::deprecation()->asInt(), $result['status']);
    }

    #[Test]
    public function record_assertions_on_finished_records_assertions_and_finishes_the_test(): void
    {
        $collector = new ResultCollector();
        $collector->testPrepared($this->testMethod()->id());
        $collector->testPassed();

        (new RecordAssertionsOnFinished($collector))->notify(new Finished($this->telemetryInfo(), $this->testMethod(), 4));

        self::assertSame(4, $collector->all()[$this->testMethod()->id()]['assertions']);
        self::assertFalse($collector->hasUnfinishedTest());
    }

    #[Test]
    public function flush_on_execution_finished_writes_the_run_partial(): void
    {
        $projectRoot = TempDir::make('subscribers-flush-root');
        $runDir = TempDir::make('subscribers-flush-run');

        try {
            $driver = new FakeCoverageDriver([[$projectRoot . '/src/Used.php' => [1 => 1]]]);
            $recorder = new Recorder($driver);
            $recorder->beginTest($projectRoot . '/tests/FooTest.php');
            $recorder->endTest();

            $collector = new ResultCollector();
            $collector->testPrepared('Foo::bar', $projectRoot . '/tests/FooTest.php');
            $collector->testPassed();
            $collector->finishTest();

            $writer = new RunWriter($runDir, $projectRoot);
            $subscriber = new FlushOnExecutionFinished(
                $writer,
                $recorder,
                $collector,
                static fn (): array => ['driver' => 'fake'],
            );

            $subscriber->notify(new ExecutionFinished($this->telemetryInfo()));

            $partial = RunPartial::load($runDir);
            self::assertNotNull($partial);
            self::assertSame(['tests/FooTest.php' => ['src/Used.php']], $partial->edges);
            self::assertSame('fake', $partial->meta['driver']);
            self::assertFalse($partial->meta['truncated']);
        } finally {
            TempDir::remove($projectRoot);
            TempDir::remove($runDir);
        }
    }

    #[Test]
    public function mark_truncated_on_execution_aborted_marks_the_next_flush_as_truncated(): void
    {
        $projectRoot = TempDir::make('subscribers-truncated-root');
        $runDir = TempDir::make('subscribers-truncated-run');

        try {
            $recorder = new Recorder(new FakeCoverageDriver([]));
            $collector = new ResultCollector();
            $writer = new RunWriter($runDir, $projectRoot);

            (new MarkTruncatedOnExecutionAborted($writer))->notify(new ExecutionAborted($this->telemetryInfo()));

            $writer->flush($recorder, $collector, ['driver' => 'fake']);

            $partial = RunPartial::load($runDir);
            self::assertNotNull($partial);
            self::assertTrue($partial->meta['truncated']);
        } finally {
            TempDir::remove($projectRoot);
            TempDir::remove($runDir);
        }
    }
}
