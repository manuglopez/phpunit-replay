<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 4: a change that only touches comments/whitespace executes
 * nothing, because ContentHash normalises them away (SPEC §4.4).
 */
final class Scenario04CommentOnlyChangeTest extends TestCase
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

    public function test_comment_only_source_change_executes_nothing(): void
    {
        $this->fixture->applyVariant('Money.comment-only.php', 'src/Money.php');

        $result = $this->fixture->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']));
        self::assertStringContainsString('0 executed (0 affected, 0 uncached)', ReplayAssert::lastLine($result['stdout']));
    }

    public function test_comment_only_test_file_change_executes_nothing(): void
    {
        $this->fixture->applyVariant('CommentedTest.comment-only.php', 'tests/CommentedTest.php');

        $result = $this->fixture->replay([]);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']));
    }
}
