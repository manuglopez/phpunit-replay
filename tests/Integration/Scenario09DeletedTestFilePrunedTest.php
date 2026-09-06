<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 9 / §7.3: deleting a test file and committing it prunes its test
 * ids from the recorded results and the file itself from the graph's known test files.
 */
final class Scenario09DeletedTestFilePrunedTest extends TestCase
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

    public function test_deleting_and_committing_a_test_file_prunes_it_from_the_graph(): void
    {
        $graphBefore = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphBefore);
        self::assertTrue($graphBefore->knowsTest('tests/DiscountTest.php'));
        self::assertNotSame([], $graphBefore->dependenciesOf('tests/DiscountTest.php'));

        $this->fixture->delete('tests/DiscountTest.php');
        $this->fixture->repo->commitAll('remove DiscountTest');

        $result = $this->fixture->replay([]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);

        $graphAfter = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graphAfter);

        self::assertFalse($graphAfter->knowsTest('tests/DiscountTest.php'));

        foreach ($graphAfter->results('main') as $testId => $result) {
            self::assertNotSame('tests/DiscountTest.php', $result['file'] ?? null, $testId . ' should have been pruned');
        }

        self::assertSame(29, count($graphAfter->results('main')));
    }
}
