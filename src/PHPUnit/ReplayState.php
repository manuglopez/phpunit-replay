<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use LogicException;
use Manuglopez\Replay\Record\CoverageDriver;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Record\ResultCollector;
use Manuglopez\Replay\Record\RunWriter;

/**
 * Static holder shared between `ReplayExtension` and, in phase 2, the `Replayable`
 * trait — a trait mixed into the user's `TestCase` has no constructor injection, so
 * a process-wide singleton is the only way to reach the same `Recorder`/
 * `ResultCollector`/`RunWriter` instances the extension registered subscribers for.
 *
 * `decide()`/`markReplayed()` (in-process replay decisions, SPEC.md §6.2/§6.3) are
 * phase 2 and are intentionally not implemented yet.
 */
final class ReplayState
{
    private static ?Mode $mode = null;

    private static ?string $root = null;

    private static ?string $stateDir = null;

    private static ?string $runId = null;

    private static ?Recorder $recorder = null;

    private static ?ResultCollector $collector = null;

    private static ?RunWriter $runWriter = null;

    private static ?float $startedAt = null;

    public static function boot(Mode $mode, string $root, string $stateDir, string $runId, ?CoverageDriver $driver): void
    {
        self::$mode = $mode;
        self::$root = $root;
        self::$stateDir = $stateDir;
        self::$runId = $runId;
        self::$recorder = $driver !== null ? new Recorder($driver) : null;
        self::$collector = new ResultCollector();
        self::$runWriter = new RunWriter($stateDir . '/runs/' . $runId, $root);
        self::$startedAt = microtime(true);
    }

    public static function isBooted(): bool
    {
        return self::$mode !== null;
    }

    public static function mode(): Mode
    {
        return self::$mode ?? throw self::notBooted();
    }

    public static function root(): string
    {
        return self::$root ?? throw self::notBooted();
    }

    public static function stateDir(): string
    {
        return self::$stateDir ?? throw self::notBooted();
    }

    public static function runId(): string
    {
        return self::$runId ?? throw self::notBooted();
    }

    /** Null when the mode did not have a coverage driver to record edges with. */
    public static function recorder(): ?Recorder
    {
        self::assertBooted();

        return self::$recorder;
    }

    public static function collector(): ResultCollector
    {
        return self::$collector ?? throw self::notBooted();
    }

    public static function runWriter(): RunWriter
    {
        return self::$runWriter ?? throw self::notBooted();
    }

    public static function startedAt(): float
    {
        return self::$startedAt ?? throw self::notBooted();
    }

    /** For tests: clears the singleton so each test starts from a clean slate. */
    public static function reset(): void
    {
        self::$mode = null;
        self::$root = null;
        self::$stateDir = null;
        self::$runId = null;
        self::$recorder = null;
        self::$collector = null;
        self::$runWriter = null;
        self::$startedAt = null;
    }

    // TODO(phase 2): ReplayState::decide(string $testFile, string $testId): Decision
    // TODO(phase 2): ReplayState::markReplayed(string $testId, Decision $decision): void
    // TODO(phase 2): ReplayState::counters(): array{affected:int, uncached:int, replayed:int, quarantined:int}

    private static function assertBooted(): void
    {
        if (self::$mode === null) {
            throw self::notBooted();
        }
    }

    private static function notBooted(): LogicException
    {
        return new LogicException('ReplayState::boot() has not been called.');
    }
}
