<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Subscribers;

use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Manuglopez\Replay\Laravel\TrackersServiceProvider;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/**
 * Arms the trackers before Laravel's `setUp()` touches the database, so that every table a
 * test reaches through its `setUp()` is recorded: `RefreshDatabase` and its `$seed`/`$seeder`,
 * every trait's `setUp<Trait>()` and `#[SetUp]` method, the ParallelTesting setUp callbacks,
 * the factories in the test's own `setUp()`.
 *
 * Laravel's `setUpTheTestEnvironment()` runs `refreshApplication()`, the ParallelTesting
 * callbacks, then `setUpTraits()`, then the `afterApplicationCreated()` callbacks: the only
 * point before the traits is the application's own bootstrap inside `createApplication()`.
 * At `Test\PreparationStarted` (before `setUp()`), for a Laravel test case, this merges
 * {@see TrackersServiceProvider} into the providers the next application registers
 * (`Illuminate\Foundation\Bootstrap\RegisterProviders::merge()`, Laravel 11+); its `boot()`
 * arms that application while it bootstraps, and marks it armed early.
 *
 * Where that cannot happen (Laravel 10, which has no `merge()`; a configuration loaded from
 * cache, for which Laravel skips merged providers; an application created some other way), the
 * trackers are armed late, by an `afterApplicationCreated()` callback or at `Prepared`, and
 * {@see ArmLaravelTrackersOnPrepared} records the test as touching unknown tables. PHPUnit's
 * event value objects do not carry the test case instance, but PHPUnit dispatches events
 * synchronously from `TestCase::runBare()`, so the instance is on the call stack.
 *
 * Not recorded, either way: queries through raw PDO, and a result an earlier test cached in a
 * static (the first test to load it records the table; `Cache\GraphUpdater` unions what every
 * recording saw).
 */
final readonly class ArmLaravelTrackersOnPreparationStarted implements PreparationStartedSubscriber
{
    private const SERVICE_PROVIDER = 'Illuminate\\Support\\ServiceProvider';

    public function __construct(private ArmLaravelTrackersOnPrepared $arming)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        $this->arming->recordUsesDatabase($test);

        $testCase = self::runningTestCase();

        if ($testCase === null || ! method_exists($testCase, 'afterApplicationCreated')) {
            return;
        }

        $this->arming->expectEarlyArming();
        $arming = $this->arming;

        self::mergeProvider(static function (object $app) use ($arming): void {
            $arming->armTrackers($app, early: true);
        });

        // The late fallback: still before the rest of the test's own setUp().
        /** @var callable $register */
        $register = [$testCase, 'afterApplicationCreated'];
        $register(static function () use ($arming): void {
            $arming->armTrackers();
        });
    }

    /** @param \Closure(object): void $arm */
    private static function mergeProvider(\Closure $arm): void
    {
        if (! class_exists(self::SERVICE_PROVIDER) || ! class_exists(RegisterProviders::class)) {
            return;
        }

        TrackersServiceProvider::armWith($arm);

        try {
            // merge() also sets the bootstrap providers path: keep the one already there.
            $path = (new ReflectionProperty(RegisterProviders::class, 'bootstrapProviderPath'))->getValue();
            RegisterProviders::merge([TrackersServiceProvider::class], is_string($path) ? $path : null);
        } catch (Throwable) {
            // Laravel 10 (no merge()), or a RegisterProviders that differs: armed late, so the
            // test is recorded as touching unknown tables.
        }
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
