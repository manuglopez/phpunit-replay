<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 8: a new test method added to an already-known test file gets
 * executed and appears in the recorded results — the test file is its own PhpEdge
 * dependency (it covers itself), so changing it selects it via PhpEdgeRule.
 */
final class Scenario08NewTestInKnownFileTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_new_test_method_in_a_known_file_executes_and_is_recorded(): void
    {
        $graphBefore = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphBefore);
        self::assertContains('tests/MoneyTest.php', $graphBefore->dependenciesOf('tests/MoneyTest.php'));

        $this->fixture->applyVariant('MoneyTest.extra-test.php', 'tests/MoneyTest.php');

        $result = $this->fixture->replay(['--explain', '--dry-run']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('tests/MoneyTest.php', $result['stdout']);
        self::assertStringContainsString('PhpEdge', $result['stdout']);

        $run = $this->fixture->replay([]);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(6, ReplayAssert::executedCount($run['stdout']));

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);

        $newTestId = 'App\Tests\MoneyTest::testZeroIsNeitherNegativeNorPositive';
        $newResult = $graphAfter->result('main', $newTestId);
        self::assertNotNull($newResult, 'the new test id should appear in the recorded results');
        self::assertSame(0, $newResult['status']);
    }
}
