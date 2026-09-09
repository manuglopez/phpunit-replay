<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\BladeTracker;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Tests\Support\TempDir;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\TestCase;

/**
 * `BladeTracker` is duck-typed against an Illuminate application on purpose (its own
 * docblock, and `TableTracker`'s: the package must not depend on illuminate/*), so it is
 * exercised here against plain stub objects implementing only the handful of methods it
 * actually calls (`bound`, `make`, `composer`, `get`, `getPath`) — the same way a real
 * container is queried dynamically. There was no dedicated test for this class before this
 * suite; `tests/Unit/Laravel/BladeReferencesTest.php` covers the (unrelated) static Blade
 * ancestor walk.
 */
final class BladeTrackerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('blade-tracker');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    // -- the case that must keep working -----------------------------------------------

    public function test_an_ordinary_source_template_is_linked(): void
    {
        $template = $this->root . '/resources/views/welcome.blade.php';
        $app = $this->app(compiledViewDirectory: $this->root . '/storage/framework/views');
        $recorder = $this->recorder();

        BladeTracker::arm($app, $recorder, $this->root);

        $recorder->beginTest('/project/tests/FooTest.php');
        $app->viewFactory->render($this->view($template));
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => [$template]],
            $recorder->perTestFiles(),
        );
    }

    // -- the defect this class exists to fix -------------------------------------------

    public function test_a_path_inside_config_view_compiled_is_never_linked(): void
    {
        $compiledDir = $this->root . '/storage/framework/views';
        $compiledView = $compiledDir . '/3a1f9c2b8e0d7a6c.php';
        $app = $this->app(compiledViewDirectory: $compiledDir);
        $recorder = $this->recorder();

        BladeTracker::arm($app, $recorder, $this->root);

        $recorder->beginTest('/project/tests/FooTest.php');
        $app->viewFactory->render($this->view($compiledView));
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    /**
     * Laravel Parallel Testing rewrites `view.compiled` per worker process during its own
     * setup, which runs AFTER `arm()`'s once-per-Container bootstrap — so the compiled
     * directory must be read fresh from config on every render, not captured once when
     * `arm()` runs.
     */
    public function test_config_view_compiled_is_read_fresh_on_every_render_not_cached_at_arm_time(): void
    {
        $recorder = $this->recorder();
        $app = $this->app(compiledViewDirectory: null);

        BladeTracker::arm($app, $recorder, $this->root);

        // Only after arm() does something (Laravel\ParallelTesting::setUpProcess(), in
        // reality) rewrite the compiled directory for this worker.
        $workerCompiledDir = $this->root . '/bootstrap/cache/views/test_3';
        $app->config->compiled = $workerCompiledDir;

        $recorder->beginTest('/project/tests/FooTest.php');
        $app->viewFactory->render($this->view($workerCompiledDir . '/deadbeefcafef00d.php'));
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    // -- belt-and-braces fallback: config unreadable ------------------------------------

    public function test_when_config_cannot_be_read_a_path_under_bootstrap_cache_is_still_refused(): void
    {
        $app = $this->app(compiledViewDirectory: null, configBound: false);
        $recorder = $this->recorder();

        BladeTracker::arm($app, $recorder, $this->root);

        $recorder->beginTest('/project/tests/FooTest.php');
        $app->viewFactory->render($this->view($this->root . '/bootstrap/cache/views/test_1/abc123.php'));
        $app->viewFactory->render($this->view($this->root . '/storage/framework/views/def456.php'));
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    public function test_when_config_cannot_be_read_an_ordinary_template_is_still_linked(): void
    {
        // The fallback must not over-exclude: refusing everything whenever config is
        // unreadable would silently break the ordinary case on any container that only
        // partially resembles Laravel's.
        $template = $this->root . '/resources/views/welcome.blade.php';
        $app = $this->app(compiledViewDirectory: null, configBound: false);
        $recorder = $this->recorder();

        BladeTracker::arm($app, $recorder, $this->root);

        $recorder->beginTest('/project/tests/FooTest.php');
        $app->viewFactory->render($this->view($template));
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => [$template]],
            $recorder->perTestFiles(),
        );
    }

    // -- defensive guards, matching the tracker's existing style ------------------------

    public function test_arm_is_a_no_op_when_view_is_not_bound(): void
    {
        $app = new class () {
            public function bound(string $name): bool
            {
                return false;
            }

            public function make(string $name): object
            {
                throw new \RuntimeException('make() must not be called for an unbound service');
            }
        };

        BladeTracker::arm($app, $this->recorder(), $this->root);

        $this->expectNotToPerformAssertions();
    }

    public function test_arm_is_a_no_op_when_the_view_factory_has_no_composer_method(): void
    {
        $app = $this->app(compiledViewDirectory: null);
        $app->viewFactory = new class () {
            // Deliberately no composer() method.
        };

        BladeTracker::arm($app, $this->recorder(), $this->root);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_view_with_no_get_path_method_is_ignored(): void
    {
        $app = $this->app(compiledViewDirectory: null);
        $recorder = $this->recorder();

        BladeTracker::arm($app, $recorder, $this->root);

        $recorder->beginTest('/project/tests/FooTest.php');
        $app->viewFactory->render(new class () {
            // No getPath() at all.
        });
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    public function test_a_non_string_get_path_is_ignored(): void
    {
        $app = $this->app(compiledViewDirectory: null);
        $recorder = $this->recorder();

        BladeTracker::arm($app, $recorder, $this->root);

        $recorder->beginTest('/project/tests/FooTest.php');
        $app->viewFactory->render($this->view(null));
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    private function recorder(): Recorder
    {
        return new Recorder(new FakeCoverageDriver([]));
    }

    /** A view stub exposing getPath(): mixed — $path may be null to exercise the "not a string" guard. */
    private function view(?string $path): object
    {
        return new class ($path) {
            public function __construct(private readonly ?string $path)
            {
            }

            public function getPath(): ?string
            {
                return $this->path;
            }
        };
    }

    /**
     * An application stub exposing exactly `bound()`/`make()`, resolving `'view'` to a
     * composer-recording view factory and, when `$configBound`, `'config'` to a stub whose
     * `get('view.compiled')` returns `$compiledViewDirectory` — reassignable afterwards
     * (`$app->config->compiled = ...`) to simulate Laravel Parallel Testing rewriting it
     * mid-run.
     */
    private function app(?string $compiledViewDirectory, bool $configBound = true): object
    {
        $viewFactory = new class () {
            /** @var list<callable> */
            private array $composers = [];

            public function composer(string $pattern, callable $callback): void
            {
                $this->composers[] = $callback;
            }

            public function render(object $view): void
            {
                foreach ($this->composers as $composer) {
                    $composer($view);
                }
            }
        };

        $config = new class ($compiledViewDirectory) {
            public function __construct(public ?string $compiled)
            {
            }

            public function get(string $key): ?string
            {
                return $key === 'view.compiled' ? $this->compiled : null;
            }
        };

        return new class ($viewFactory, $config, $configBound) {
            public function __construct(
                public object $viewFactory,
                public object $config,
                private readonly bool $configBound,
            ) {
            }

            public function bound(string $name): bool
            {
                return match ($name) {
                    'view' => true,
                    'config' => $this->configBound,
                    default => false,
                };
            }

            public function make(string $name): object
            {
                return match ($name) {
                    'view' => $this->viewFactory,
                    'config' => $this->config,
                    default => throw new \RuntimeException('unexpected make(' . $name . ')'),
                };
            }
        };
    }
}
