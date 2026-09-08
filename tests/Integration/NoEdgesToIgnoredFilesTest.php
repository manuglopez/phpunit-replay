<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * The end-to-end regression the no-edges-to-ignored-files fix exists for: `Blade::render(
 * $string)` makes Laravel write the raw template content into `config('view.compiled')`
 * itself (`Illuminate\View\Component::createBladeViewFromString()`, verified against the
 * real installed Laravel v13.30.1 in tests/Fixtures/Projects/laravel-lite/vendor), under a
 * content-hashed `<hash>.blade.php` name registered under the `__components::` view
 * namespace — the SAME path `Laravel\BladeTracker`'s view composer sees via
 * `$view->getPath()`. That directory (this fixture's `storage/framework/views/`, its
 * `config/view.php` `'compiled'`) is `.gitignore`d here exactly like a real project's
 * `bootstrap/cache/`.
 *
 * A separate fixture copy from `tests/Integration/LaravelLiteScenariosTest.php` on purpose:
 * every one of that class's scenarios asserts an exact "N tests would replay/executed"
 * count off the shared 4-test laravel-lite fixture, and adding a 5th test file to the
 * shared fixture source would ripple through every one of those unrelated assertions. This
 * test instead writes its own extra test file into its own fixture copy, after
 * `FixtureProject::laravelLite()` but before recording, so nothing else is affected.
 */
final class NoEdgesToIgnoredFilesTest extends TestCase
{
    private ?FixtureProject $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->destroy();

        parent::tearDown();
    }

    public function test_an_inline_blade_render_records_no_edge_to_the_compiled_cache_but_keeps_the_real_template_edge(): void
    {
        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped(
                'tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.',
            );
        }

        $this->fixture = FixtureProject::laravelLite();

        $this->fixture->write('tests/Feature/InlineBladeRenderTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Tests\Feature;

            use Illuminate\Support\Facades\Blade;
            use Tests\TestCase;

            class InlineBladeRenderTest extends TestCase
            {
                public function test_it_renders_an_inline_blade_string(): void
                {
                    $html = Blade::render('<p>Hello, {{ $name }}!</p>', ['name' => 'World']);

                    $this->assertStringContainsString('Hello, World!', $html);
                }
            }
            PHP);

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertTrue($graph->knowsTest('tests/Feature/InlineBladeRenderTest.php'));

        // The mechanism actually fired — otherwise a green assertion below would be
        // meaningless (nothing to have gotten wrong in the first place).
        $compiled = glob($this->fixture->root() . '/storage/framework/views/*.blade.php') ?: [];
        self::assertNotSame(
            [],
            $compiled,
            'Blade::render() did not write a compiled artifact under storage/framework/views — '
            . 'this test no longer exercises the scenario it is meant to.',
        );

        // The defect, measured: every recorded dependency file across the WHOLE graph, not
        // just this one test's edges (Layer 2 is a net that catches any writer).
        foreach ($graph->files() as $file) {
            self::assertFalse(
                str_starts_with($file, 'storage/framework/views/') && str_ends_with($file, '.blade.php'),
                $file . ' is a disposable compiled Blade view and must never be a recorded dependency',
            );
        }

        // Layer 1's own guard rail: the fix must not break the ordinary case. HomePageTest
        // renders a REAL source template and must keep its edge.
        self::assertContains(
            'resources/views/welcome.blade.php',
            $graph->dependenciesOf('tests/Feature/HomePageTest.php'),
        );
    }
}
