<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use LogicException;
use Manuglopez\Replay\PHPUnit\Mode;
use Manuglopez\Replay\PHPUnit\ReplayState;
use Manuglopez\Replay\Record\ResultCollector;
use Manuglopez\Replay\Record\RunWriter;
use Manuglopez\Replay\Tests\Support\TempDir;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class ReplayStateTest extends TestCase
{
    #[After]
    public function resetReplayState(): void
    {
        ReplayState::reset();
    }

    public function test_is_not_booted_before_boot_is_called(): void
    {
        self::assertFalse(ReplayState::isBooted());
    }

    public function test_getters_throw_before_boot(): void
    {
        $this->expectException(LogicException::class);

        ReplayState::mode();
    }

    public function test_boot_populates_mode_root_state_dir_and_run_id(): void
    {
        $root = TempDir::make('replay-state-root');

        try {
            ReplayState::boot(Mode::Record, $root, $root . '/.phpunit-replay', 'run-1', new FakeCoverageDriver([]));

            self::assertTrue(ReplayState::isBooted());
            self::assertSame(Mode::Record, ReplayState::mode());
            self::assertSame($root, ReplayState::root());
            self::assertSame($root . '/.phpunit-replay', ReplayState::stateDir());
            self::assertSame('run-1', ReplayState::runId());
        } finally {
            TempDir::remove($root);
        }
    }

    public function test_recorder_is_present_when_a_driver_is_given(): void
    {
        $root = TempDir::make('replay-state-recorder');

        try {
            ReplayState::boot(Mode::Record, $root, $root . '/.phpunit-replay', 'run-1', new FakeCoverageDriver([]));

            self::assertNotNull(ReplayState::recorder());
        } finally {
            TempDir::remove($root);
        }
    }

    public function test_recorder_is_null_when_no_driver_is_given(): void
    {
        $root = TempDir::make('replay-state-no-driver');

        try {
            ReplayState::boot(Mode::ResultsOnly, $root, $root . '/.phpunit-replay', 'run-1', null);

            self::assertNull(ReplayState::recorder());
        } finally {
            TempDir::remove($root);
        }
    }

    public function test_collector_and_run_writer_are_always_available_once_booted(): void
    {
        $root = TempDir::make('replay-state-collector');

        try {
            ReplayState::boot(Mode::ResultsOnly, $root, $root . '/.phpunit-replay', 'run-1', null);

            self::assertInstanceOf(ResultCollector::class, ReplayState::collector());
            self::assertInstanceOf(RunWriter::class, ReplayState::runWriter());
        } finally {
            TempDir::remove($root);
        }
    }

    public function test_started_at_is_set_at_boot_time(): void
    {
        $root = TempDir::make('replay-state-started-at');

        try {
            $before = microtime(true);
            ReplayState::boot(Mode::ResultsOnly, $root, $root . '/.phpunit-replay', 'run-1', null);
            $after = microtime(true);

            self::assertGreaterThanOrEqual($before, ReplayState::startedAt());
            self::assertLessThanOrEqual($after, ReplayState::startedAt());
        } finally {
            TempDir::remove($root);
        }
    }

    public function test_reset_returns_the_state_to_unbooted(): void
    {
        $root = TempDir::make('replay-state-reset');

        try {
            ReplayState::boot(Mode::Record, $root, $root . '/.phpunit-replay', 'run-1', new FakeCoverageDriver([]));
            ReplayState::reset();

            self::assertFalse(ReplayState::isBooted());
        } finally {
            TempDir::remove($root);
        }
    }
}
