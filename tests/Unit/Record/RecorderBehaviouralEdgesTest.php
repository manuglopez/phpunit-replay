<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Analysis\FactsCache;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The `static_declaration_edges` half of the recorder (SPEC.md §4.3.1): an edge is only
 * attributed when the executed lines landed inside a function/method/closure body.
 */
#[Group('static-declaration-edges')]
final class RecorderBehaviouralEdgesTest extends TestCase
{
    private string $root;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->root = TempDir::make('recorder-root');
        $this->stateDir = TempDir::make('recorder-state');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
        TempDir::remove($this->stateDir);
    }

    #[Test]
    public function a_file_whose_executed_lines_are_inside_a_method_body_is_attributed(): void
    {
        $file = $this->write('src/Policy.php', <<<'PHP'
            <?php
            namespace App;
            final class Policy {
                public function decide(): int {
                    return 1;
                }
            }
            PHP);

        $recorder = $this->recorder([[$file => [4 => 0, 5 => 1]]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame(['/project/tests/FooTest.php' => [$file]], $recorder->perTestFiles());
    }

    #[Test]
    public function a_declaration_only_file_is_not_attributed_however_its_lines_were_covered(): void
    {
        // This is the whole point: PHP runs these lines once per process, so crediting
        // them to the test that happened to be first is not a weak signal, it is noise.
        $file = $this->write('src/Decision.php', <<<'PHP'
            <?php
            namespace App;
            enum Decision: string {
                case Approved = 'approved';
                case Rejected = 'rejected';
            }
            PHP);

        $recorder = $this->recorder([[$file => [3 => 1, 4 => 1, 5 => 1]]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    #[Test]
    public function class_and_const_lines_of_a_mixed_file_are_not_enough_on_their_own(): void
    {
        $file = $this->write('src/Blended.php', <<<'PHP'
            <?php
            namespace App;
            final class Blended {
                public const RATE = 0.21;
                public function apply(int $n): float {
                    return $n * self::RATE;
                }
            }
            PHP);

        $recorder = $this->recorder([[$file => [3 => 1, 4 => 1, 6 => 0]]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    #[Test]
    public function a_value_object_whose_only_body_is_an_empty_constructor_keeps_its_edge(): void
    {
        // Regression: an empty concrete body used to contribute no range, so this file
        // classified as declaration-only and lost its edge for every test, always. pcov
        // credits the closing-brace line of a called empty body — line 4 here.
        $file = $this->write('src/Dto.php', <<<'PHP'
            <?php
            namespace App;
            final class Dto {
                public function __construct(public readonly int $id) {}
            }
            PHP);

        $recorder = $this->recorder([[$file => [3 => 1, 4 => 1]]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame(['/project/tests/FooTest.php' => [$file]], $recorder->perTestFiles());
    }

    #[Test]
    public function the_same_value_object_merely_loaded_still_gets_no_edge(): void
    {
        // The other half: pcov reports the empty body as unexecuted after a plain require,
        // so a load-only footprint is still correctly not an edge.
        $file = $this->write('src/Dto.php', <<<'PHP'
            <?php
            namespace App;
            final class Dto {
                public function __construct(public readonly int $id) {}
            }
            PHP);

        $recorder = $this->recorder([[$file => [3 => 1, 4 => 0]]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    #[Test]
    public function an_unparseable_file_falls_back_to_the_pest_heuristic_rather_than_losing_its_edge(): void
    {
        // "Cannot classify" must never collapse into "no dependencies" — that is the exact
        // false green this mechanism exists to remove.
        $file = $this->write('src/Broken.php', '<?php final class { function ( }');

        $recorder = $this->recorder([[$file => [1 => 1, 2 => 0]]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame(['/project/tests/FooTest.php' => [$file]], $recorder->perTestFiles());
    }

    #[Test]
    public function an_unparseable_file_still_gets_the_pest_autoload_exception(): void
    {
        $file = $this->write('src/Broken.php', '<?php final class { function ( }');

        $recorder = $this->recorder([[$file => [5 => 0, 10 => 0, 20 => 1]]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame([], $recorder->perTestFiles());
    }

    #[Test]
    public function with_no_facts_cache_the_recorder_behaves_exactly_as_before(): void
    {
        // The flag-off path: a declaration-only file whose lines were covered still gets
        // the edge, because that is what this package does today.
        $file = $this->write('src/Decision.php', <<<'PHP'
            <?php
            namespace App;
            enum Decision: string {
                case Approved = 'approved';
                case Rejected = 'rejected';
            }
            PHP);

        $recorder = new Recorder(new FakeCoverageDriver([[$file => [3 => 1, 4 => 1, 5 => 1]]]));
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->endTest();

        self::assertSame(['/project/tests/FooTest.php' => [$file]], $recorder->perTestFiles());
    }

    #[Test]
    public function link_source_is_untouched_by_the_filter(): void
    {
        // Explicit links (Laravel Blade tracking, SPEC.md §10) never went through coverage
        // in the first place, so they are not the recorder's to second-guess. That
        // principle still holds for a real template, and this test still proves exactly
        // that — for the OTHER half (an explicit link to a generated compiled view must be
        // refused), see the sibling test directly below and
        // tests/Unit/Laravel/BladeTrackerTest.php: the caller (Laravel\BladeTracker)
        // decides BEFORE ever calling linkSource(), because only it knows the difference
        // between a source template and `config('view.compiled')`'s disposable output —
        // Recorder itself has (and needs) no such knowledge.
        $recorder = $this->recorder([[]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->linkSource('/project/resources/views/mail.blade.php');
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => ['/project/resources/views/mail.blade.php']],
            $recorder->perTestFiles(),
        );
    }

    #[Test]
    public function link_source_still_does_not_second_guess_a_path_shaped_like_a_compiled_view(): void
    {
        // Reinforces the architectural boundary the test above documents: even a path that
        // LOOKS exactly like what Laravel\BladeTracker now refuses to forward (a compiled
        // view under bootstrap/cache/) is recorded without question when it reaches
        // linkSource() directly, because Recorder has no SourceScope/config awareness at
        // all and must not grow any — that filtering is BladeTracker's job, one layer up,
        // precisely because it is the only caller that knows what `view.compiled` is.
        $recorder = $this->recorder([[]]);
        $recorder->beginTest('/project/tests/FooTest.php');
        $recorder->linkSource('/project/bootstrap/cache/views/test_3/3a1f9c2b8e0d7a6c.php');
        $recorder->endTest();

        self::assertSame(
            ['/project/tests/FooTest.php' => ['/project/bootstrap/cache/views/test_3/3a1f9c2b8e0d7a6c.php']],
            $recorder->perTestFiles(),
        );
    }

    /** @param list<array<string, array<int, int>>> $snapshots */
    private function recorder(array $snapshots): Recorder
    {
        return new Recorder(
            new FakeCoverageDriver($snapshots),
            new FactsCache($this->stateDir, $this->root),
        );
    }

    private function write(string $relative, string $content): string
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $content);

        return $path;
    }
}
