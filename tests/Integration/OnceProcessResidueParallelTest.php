<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * The acceptance property (docs/reproducibility.md "Once-per-process residue"): recording an
 * identical tree serially and in parallel must produce the SAME graph.
 *
 * Two sequential passes agreeing (as `StaticDeclarationEdgesTest` already pins for the
 * declaration/first-loader case) proves far less than it looks. Serial and parallel are the
 * two *extremes* of first-loader attribution, not two samples of the same thing: serial (one
 * process for the whole suite) makes a once-per-process body execute once in the entire run,
 * crediting exactly one test — the minimum possible attribution; parallel (one process per
 * worker) executes it once per worker, crediting up to one test per worker; a fully
 * process-isolated run would credit every test that triggers it. Serial is reproducible
 * precisely BECAUSE it makes one deterministic arbitrary choice every time, not because the
 * choice is somehow representative. Only comparing serial against parallel actually exercises
 * both ends of that range.
 *
 * `app/Console/Commands/OnceProcessDemo.php` reproduces the shape by hand rather than relying
 * on a real `Illuminate\Console\Command`: a guard checked BEFORE ever calling into it (never
 * inside its own method — that would make every caller touch some line of it, just a
 * different one, which is not the bug), exactly mirroring how Laravel's own
 * `RefreshDatabase` trait checks `RefreshDatabaseState::$migrated` before ever invoking the
 * migrator, never inside a migration itself. The class name is built from string fragments
 * with no backslash in any single literal (`implode('\\', [...])`), deliberately unparseable
 * as a name reference by `Analysis\FactsVisitor` — a literal `use App\Console\Commands\OnceProcessDemo;`
 * would let the STATIC hop supply the same edge regardless of coverage shape, which would
 * make this test pass on an unfixed `Cache\GraphUpdater` too and prove nothing.
 *
 * `laravel-lite`'s own real migrations (`database/migrations/*.php`, run through
 * `Illuminate\Foundation\Testing\RefreshDatabase` against a shared `:memory:` SQLite
 * connection kept alive for the process, exactly the once-per-process shape) are left
 * completely alone here and add to the same proof: widening the suite from 4 to 12 test
 * files changes how Paratest distributes work across `-p 4`, which is what exposes their
 * shape-dependence too (confirmed empirically while writing this test: unfixed, the two
 * passes disagree on both the synthetic command AND on which of `PostsIndexTest`/
 * `UserModelTest` holds the migration edges).
 */
final class OnceProcessResidueParallelTest extends TestCase
{
    /** @var array<string, string> */
    private const FLAG_ON = ['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES' => '1'];

    private const ONCE_PROCESS_COMMAND = 'app/Console/Commands/OnceProcessDemo.php';

    /** @var list<string> */
    private const ONCE_PROCESS_TEST_NAMES = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta', 'Eta', 'Theta'];

    public function test_serial_and_parallel_recordings_produce_the_same_graph(): void
    {
        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped('tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.');
        }

        if (! FixtureProject::paratestAvailable()) {
            self::markTestSkipped('vendor/bin/paratest is not installed in this package (composer install --no-dev?).');
        }

        $serial = FixtureProject::laravelLite();
        $parallel = FixtureProject::laravelLite();

        try {
            $this->addOnceProcessFixture($serial);
            $this->addOnceProcessFixture($parallel);

            $serialResult = $serial->replay(['record'], self::FLAG_ON);
            self::assertSame(0, $serialResult['exitCode'], $serialResult['stdout'] . $serialResult['stderr']);

            $parallelResult = $parallel->replay(['record', '-p', '4'], self::FLAG_ON);
            self::assertSame(0, $parallelResult['exitCode'], $parallelResult['stdout'] . $parallelResult['stderr']);

            $serialGraph = ReplayAssert::loadGraph($serial);
            $parallelGraph = ReplayAssert::loadGraph($parallel);
            self::assertNotNull($serialGraph);
            self::assertNotNull($parallelGraph);

            self::assertSame(
                $this->edgeSet($serialGraph),
                $this->edgeSet($parallelGraph),
                'a serial and a parallel recording of the identical tree must produce the identical graph',
            );

            // Not vacuous: this is WHY the two agree, not a coincidence of the diff being empty
            // for an unrelated reason. Neither shape ever assigns the command a fileId at all.
            self::assertNull($serialGraph->fileId(self::ONCE_PROCESS_COMMAND));
            self::assertNull($parallelGraph->fileId(self::ONCE_PROCESS_COMMAND));
        } finally {
            $serial->destroy();
            $parallel->destroy();
        }
    }

    /**
     * Adds a once-per-process console command and enough test files calling it (via a
     * before-the-call guard, see the class docblock) that Paratest's `-p 4` has more than one
     * file per worker to distribute — the shape that exposed the bug while writing this test.
     */
    private function addOnceProcessFixture(FixtureProject $fixture): void
    {
        $fixture->write(self::ONCE_PROCESS_COMMAND, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Console\Commands;

            final class OnceProcessDemo
            {
                public static bool $triggered = false;

                public function run(): int
                {
                    $doubled = 1 + 1;

                    return $doubled;
                }
            }
            PHP);

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Tests\Feature;

            use Tests\TestCase;

            final class OnceProcess__NAME__Test extends TestCase
            {
                public function test_it_triggers_the_once_process_command(): void
                {
                    $class = implode('\\', ['App', 'Console', 'Commands', 'OnceProcessDemo']);

                    if (! $class::$triggered) {
                        $class::$triggered = true;
                        (new $class())->run();
                    }

                    $this->assertTrue(true);
                }
            }
            PHP;

        foreach (self::ONCE_PROCESS_TEST_NAMES as $name) {
            $fixture->write(
                "tests/Feature/OnceProcess{$name}Test.php",
                str_replace('__NAME__', $name, $template),
            );
        }
    }

    /** @return list<string> "<test file> -> <source file>", sorted */
    private function edgeSet(Graph $graph): array
    {
        $edges = [];

        foreach ($graph->allTestFiles() as $testFile) {
            foreach ($graph->dependenciesOf($testFile) as $dependency) {
                $edges[] = $testFile . ' -> ' . $dependency;
            }
        }

        sort($edges);

        return $edges;
    }
}
