<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use Manuglopez\Replay\PHPUnit\ReplayState;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

/**
 * `#[Depends]` providers must never be replayed: the dependent test would receive `null`
 * instead of the provider's return value (docs/spikes/in-process-replay.md row B5).
 * The `plain` fixture's `DependsTest` is the real-world shape this is detected on.
 */
final class DependsProviderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Loaded by hand: the fixture project has its own autoloader, and this class is
        // only ever inspected through reflection, never run as a test here.
        if (! class_exists(\App\Tests\DependsTest::class, false)) {
            require_once dirname(__DIR__, 2) . '/Fixtures/Projects/plain/tests/DependsTest.php';
        }
    }

    #[After]
    public function resetReplayState(): void
    {
        ReplayState::reset();
    }

    public function test_a_method_another_test_depends_on_is_a_provider(): void
    {
        self::assertTrue(ReplayState::isDependsProvider(\App\Tests\DependsTest::class, 'testFirst'));
    }

    public function test_the_dependent_test_itself_is_not_a_provider(): void
    {
        self::assertFalse(ReplayState::isDependsProvider(\App\Tests\DependsTest::class, 'testSecondUsesReturnedMoney'));
    }

    public function test_a_class_without_depends_has_no_providers(): void
    {
        self::assertFalse(ReplayState::isDependsProvider(self::class, 'test_a_class_without_depends_has_no_providers'));
    }

    public function test_an_unknown_class_is_never_a_provider(): void
    {
        self::assertFalse(ReplayState::isDependsProvider('Totally\Missing\ClassName', 'testFirst'));
    }
}
