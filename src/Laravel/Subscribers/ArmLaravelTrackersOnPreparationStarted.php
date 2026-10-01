<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Subscribers;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Arms the trackers inside `setUp()`, as soon as Laravel has created the test's application,
 * so that the tables a test's `setUp()` and its factories query are recorded too.
 *
 * `Test\Prepared` ({@see ArmLaravelTrackersOnPrepared}) fires after `setUp()` has returned,
 * and `Test\PreparationStarted` before Laravel's `TestCase::setUp()` creates the application
 * the queries go through, so neither event alone can arm it in time. Laravel's own hook can:
 * `afterApplicationCreated()` runs its callbacks right after `refreshApplication()` and the
 * testing traits (`RefreshDatabase` has migrated by then), before the rest of `setUp()`. This
 * subscriber registers one on the running test case at `PreparationStarted`. PHPUnit's event
 * value objects do not carry the test case instance, but PHPUnit dispatches events
 * synchronously from `TestCase::runBare()`, so the instance is on the call stack; a test
 * class that is not a Laravel test case (no `afterApplicationCreated()`) is left to the
 * `Prepared` fallback, which also covers an application created some other way.
 *
 * Not covered, as before: queries a test runs before `afterApplicationCreated` fires (a
 * seeder `RefreshDatabase` runs), through raw PDO, or from a result an earlier test cached in
 * a static (the first loader records the table, the others do not).
 */
final readonly class ArmLaravelTrackersOnPreparationStarted implements PreparationStartedSubscriber
{
    public function __construct(private ArmLaravelTrackersOnPrepared $arming)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        if (! $event->test() instanceof TestMethod) {
            return;
        }

        $testCase = self::runningTestCase();

        if ($testCase === null || ! method_exists($testCase, 'afterApplicationCreated')) {
            return;
        }

        $arming = $this->arming;

        /** @var callable $register */
        $register = [$testCase, 'afterApplicationCreated'];
        $register(static function () use ($arming): void {
            $arming->armTrackers();
        });
    }

    private static function runningTestCase(): ?TestCase
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 32) as $frame) {
            $object = $frame['object'] ?? null;

            if ($object instanceof TestCase) {
                return $object;
            }
        }

        return null;
    }
}
