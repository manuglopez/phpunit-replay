<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 3: a real (non-cosmetic) source change re-executes exactly the
 * test files with an edge to it — verified both via `--explain --dry-run` and via the
 * real run's own `N executed` count.
 *
 * Every test file in tests/Fixtures/Projects/plain that covers src/Money.php: Cart,
 * TaxCalculator and Discount all construct/manipulate Money objects internally, so pcov
 * sees their tests exercise Money.php too (confirmed against the real fixture); Greeter
 * never touches Money at all and must stay untouched.
 */
final class Scenario03SourceChangeTest extends TestCase
{
    private const MONEY_DEPENDENTS = [
        'tests/CartTest.php',
        'tests/CommentedTest.php',
        'tests/DependsTest.php',
        'tests/DiscountTest.php',
        'tests/MoneyTest.php',
        'tests/TaxCalculatorTest.php',
    ];

    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->fixture->applyVariant('Money.behaviour.php', 'src/Money.php');
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_only_dependents_of_the_changed_source_execute(): void
    {
        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);

        foreach (self::MONEY_DEPENDENTS as $testFile) {
            self::assertTrue($graph->knowsTest($testFile), $testFile . ' should be known to the graph');
            self::assertContains('src/Money.php', $graph->dependenciesOf($testFile), $testFile . ' should depend on src/Money.php');
        }

        self::assertNotContains('src/Money.php', $graph->dependenciesOf('tests/GreeterTest.php'));

        $expectedTests = self::countResultsInFiles($graph, self::MONEY_DEPENDENTS);
        self::assertGreaterThan(0, $expectedTests);

        $explain = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $explain['exitCode'], $explain['stdout'] . $explain['stderr']);

        $explainedFiles = self::explainedFiles($explain['stdout']);
        sort($explainedFiles);
        $expectedDependents = self::MONEY_DEPENDENTS;
        sort($expectedDependents);
        self::assertSame($expectedDependents, $explainedFiles);
        self::assertNotContains('tests/GreeterTest.php', $explainedFiles);

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame($expectedTests, ReplayAssert::executedCount($result['stdout']));

        self::assertStringNotContainsString('GreeterTest', $result['stdout']);
    }

    /** @param list<string> $files */
    private static function countResultsInFiles(Graph $graph, array $files): int
    {
        $count = 0;
        $set = array_fill_keys($files, true);

        foreach ($graph->results('main') as $result) {
            if (isset($set[$result['file'] ?? ''])) {
                $count++;
            }
        }

        return $count;
    }

    /** @return list<string> */
    private static function explainedFiles(string $stdout): array
    {
        $files = [];

        foreach (explode("\n", rtrim($stdout)) as $line) {
            if (preg_match('/^(\S+)\s+←/', $line, $m) === 1) {
                $files[] = $m[1];
            }
        }

        return $files;
    }
}
