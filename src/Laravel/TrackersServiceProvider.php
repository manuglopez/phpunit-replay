<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Closure;
use Illuminate\Support\ServiceProvider;

/**
 * Arms the trackers while Laravel bootstraps a test's application: registered by
 * `Subscribers\ArmLaravelTrackersOnPreparationStarted` through
 * `Illuminate\Foundation\Bootstrap\RegisterProviders::merge()` for the one test about to run,
 * and only while a recorder is active, so its `boot()` runs inside `createApplication()`,
 * before `setUp()` runs any testing trait (`RefreshDatabase` and its seeder, every
 * `setUp<Trait>()`, the ParallelTesting setUp callbacks). Laravel's `tearDown()` flushes the
 * merge list (`RegisterProviders::flushState()`), so it never outlives the test.
 *
 * Loaded only by that path, which checks `Illuminate\Support\ServiceProvider` exists first:
 * the package does not depend on `illuminate/*`.
 */
final class TrackersServiceProvider extends ServiceProvider
{
    /** @var (Closure(object): mixed)|null */
    private static ?Closure $arm = null;

    /** @param (Closure(object): mixed)|null $arm what to run on the booting application */
    public static function armWith(?Closure $arm): void
    {
        self::$arm = $arm;
    }

    public function boot(): void
    {
        if (self::$arm !== null) {
            (self::$arm)($this->app);
        }
    }
}
