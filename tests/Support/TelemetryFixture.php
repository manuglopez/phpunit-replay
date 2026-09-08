<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Support;

use PHPUnit\Event\Telemetry;
use ReflectionClass;

/**
 * A zeroed `PHPUnit\Event\Telemetry\Info`, which every `PHPUnit\Event\Test\*` event this
 * package subscribes to needs as its first constructor argument.
 *
 * Built through reflection because these constructors are explicitly outside PHPUnit's
 * backward compatibility promise and PHPUnit 13.3 added CPU-time arguments to both:
 * `Telemetry\Snapshot` went from four parameters to seven and `Telemetry\Info` from five to
 * eleven. A fixed argument list is an `ArgumentCountError` on one side of that line or the
 * other, and every subscriber test in the suite goes through here.
 */
final class TelemetryFixture
{
    public static function info(): Telemetry\Info
    {
        return self::build(Telemetry\Info::class, [
            self::snapshot(),
            Telemetry\Duration::fromSecondsAndNanoseconds(0, 0),
            Telemetry\MemoryUsage::fromBytes(0),
            Telemetry\Duration::fromSecondsAndNanoseconds(0, 0),
            Telemetry\MemoryUsage::fromBytes(0),
        ]);
    }

    private static function snapshot(): Telemetry\Snapshot
    {
        return self::build(Telemetry\Snapshot::class, [
            Telemetry\HRTime::fromSecondsAndNanoseconds(0, 0),
            Telemetry\MemoryUsage::fromBytes(0),
            Telemetry\MemoryUsage::fromBytes(0),
            new Telemetry\GarbageCollectorStatus(0, 0, 0, 0, 0.0, 0.0, 0.0, 0.0, false, false, false, 0),
        ]);
    }

    /**
     * `$arguments` padded out to the installed constructor's arity with zeroed `CpuTime`s,
     * which is what both classes gained in PHPUnit 13.3 (three on `Snapshot`, six on `Info`).
     *
     * @template T of object
     * @param class-string<T> $class
     * @param list<object> $arguments
     * @return T
     */
    private static function build(string $class, array $arguments): object
    {
        $reflection = new ReflectionClass($class);
        $wanted = $reflection->getConstructor()?->getNumberOfParameters() ?? count($arguments);

        while (count($arguments) < $wanted) {
            $arguments[] = Telemetry\CpuTime::fromSecondsAndNanoseconds(0, 0);
        }

        return $reflection->newInstanceArgs($arguments);
    }
}
