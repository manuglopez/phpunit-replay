<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay status` (SPEC.md §11): before a baseline exists, and after recording one.
 */
final class StatusCommandTest extends TestCase
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

    public function test_status_before_a_baseline_exists(): void
    {
        $result = $this->fixture->replay(['status']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('root:', $result['stdout']);
        self::assertStringContainsString($this->fixture->root(), $result['stdout']);
        self::assertStringContainsString('branch:    main', $result['stdout']);
        self::assertStringContainsString('driver:', $result['stdout']);
        // Driver-agnostic: the fixture subprocess picks whatever coverage driver is
        // actually loaded (pcov preferred, xdebug as fallback — SPEC §5.1); a CI cell
        // running under xdebug alone must not fail an assertion hardcoded to "pcov".
        self::assertStringContainsString(DriverDetector::loadedExtension() ?? 'none', $result['stdout']);
        self::assertStringContainsString('framework: plain', $result['stdout']);
        self::assertStringContainsString('mirror:    0 objects · 0 reachable · 0 KB reclaimable', $result['stdout']);
        self::assertStringContainsString('no baseline yet', $result['stdout']);
    }

    public function test_status_after_a_baseline_is_recorded(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $result = $this->fixture->replay(['status']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertStringContainsString('files:', $result['stdout']);
        self::assertStringContainsString('test files: 7', $result['stdout']);
        self::assertStringContainsString('edges:', $result['stdout']);
        self::assertStringContainsString('graph.json:', $result['stdout']);
        self::assertStringContainsString('main', $result['stdout']);
        self::assertStringContainsString('complete', $result['stdout']);
        self::assertStringContainsString('35 results', $result['stdout']);
        self::assertStringContainsString('fingerprint:', $result['stdout']);
        // No remote configured in this fixture, so the mirror this machine can address is
        // empty regardless of how many results the baseline itself just recorded.
        self::assertStringContainsString('mirror:    0 objects · 0 reachable · 0 KB reclaimable', $result['stdout']);
        self::assertStringContainsString('quarantined: 0', $result['stdout']);
        self::assertStringNotContainsString('no baseline yet', $result['stdout']);
    }
}
