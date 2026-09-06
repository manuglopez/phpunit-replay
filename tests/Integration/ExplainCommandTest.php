<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay explain <path>` (SPEC.md §11): which recorded test files a change to
 * `<path>` would affect, and why.
 */
final class ExplainCommandTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_without_a_baseline_it_fails_with_a_clear_message(): void
    {
        $result = $this->fixture->replay(['explain', 'src/Money.php']);

        self::assertSame(1, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('no baseline yet', $result['stdout']);
    }

    public function test_explain_lists_the_money_dependent_test_files(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $result = $this->fixture->replay(['explain', 'src/Money.php']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('PhpEdge', $result['stdout']);
        self::assertStringContainsString('tests/MoneyTest.php', $result['stdout']);
        self::assertStringContainsString('tests/CartTest.php', $result['stdout']);
        self::assertStringContainsString('direct dependents:', $result['stdout']);
        self::assertStringNotContainsString('no recorded test executes this file', $result['stdout']);
    }

    public function test_explain_on_a_file_no_recorded_test_executes_says_so(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $result = $this->fixture->replay(['explain', 'README.md']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('no recorded test executes this file', $result['stdout']);
        self::assertStringContainsString('direct dependents: 0', $result['stdout']);
    }
}
