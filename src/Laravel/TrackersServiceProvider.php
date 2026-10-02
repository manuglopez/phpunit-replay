<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use Closure;
use Illuminate\Support\ServiceProvider;

/**
 * Arms the trackers while Laravel bootstraps a test's application: registered by
 * `Subscribers\ArmLaravelTrackersOnPreparationStarted` through
 * `Illuminate\Foundation\Bootstrap\RegisterProviders::merge()` for the one test about to run,
 * and only while a recorder is active. Its `register()` adds a `booting()` callback, which
 * `Application::boot()` runs before it boots any provider: the trackers are armed inside
 * `createApplication()`, before every provider's `boot()` and before `setUp()` runs any
 * testing trait (`RefreshDatabase` and its seeder, every `setUp<Trait>()`, the ParallelTesting
 * setUp callbacks). A query in a provider's `register()` runs earlier, and is not seen.
 * Laravel's `tearDown()` flushes the merge list (`RegisterProviders::flushState()`), so it
 * never outlives the test.
 *
 * Side effect: Laravel's provider manifest (`bootstrap/cache/services.php`) is recompiled to
 * list this provider while tests record, and recompiled again without it by the next
 * `artisan` or request that boots without it (`ProviderRepository::shouldRecompile()`). It is
 * a generated, git-ignored file; nothing outside a recording ever loads the provider.
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

    /**
     * Arms from a `booting()` callback: `Application::boot()` fires those before it boots any
     * provider, so a query in another provider's `boot()` (a framework one, a package's, one
     * of `config('app.providers')`, `bootstrap/providers.php`) is seen too, whichever order the
     * providers are registered in. A query in a provider's `register()` runs before this.
     */
    public function register(): void
    {
        $arm = self::$arm;
        $app = $this->app;

        if ($arm !== null && method_exists($app, 'booting')) {
            $app->booting(static function () use ($arm, $app): void {
                $arm($app);
            });
        }
    }
}
