<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay baseline-path` (SPEC.md §11): prints the resolved state directory and
 * nothing else, so CI can archive it as a build artifact.
 */
final class BaselinePathCommandTest extends TestCase
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

    public function test_baseline_path_prints_exactly_the_state_directory(): void
    {
        $result = $this->fixture->replay(['baseline-path']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(ReplayAssert::stateDir($this->fixture), rtrim($result['stdout'], "\n"));
    }

    public function test_baseline_path_is_stable_before_and_after_recording(): void
    {
        $before = $this->fixture->replay(['baseline-path']);
        self::assertSame(0, $before['exitCode']);

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $after = $this->fixture->replay(['baseline-path']);
        self::assertSame(0, $after['exitCode']);

        self::assertSame(rtrim($before['stdout'], "\n"), rtrim($after['stdout'], "\n"));
    }
}
