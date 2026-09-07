<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * `phpunit-replay verify` (SPEC.md §12.2): a full-suite recording pass that compares
 * every result against the graph's existing baseline and reports the lifetime
 * divergence count. Uses `FIXTURE_FAIL` (already read by
 * `App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet` in the `plain` fixture) to
 * force exactly one test to diverge — the same hook scenario 5 uses, and equivalent for
 * this purpose to a dedicated `FIXTURE_FLIP` fixture.
 */
final class VerifyCommandTest extends TestCase
{
    /** tests/Fixtures/Projects/plain has 35 tests: 34 pass, 1 (`MoneyTest::testSkippedOnPurpose`) is skipped. Neither status forces a rerun under this fixture's phpunit.xml (no failOnSkipped/displayDetailsOnSkippedTests), so a clean verify says all 35 would replay. */
    private const TOTAL_TESTS = 35;

    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_verify_after_a_clean_record_finds_no_divergences(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $verify = $this->fixture->replay(['verify']);

        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);
        self::assertStringContainsString('✓', $verify['stdout']);
        self::assertStringContainsString(sprintf('%d tests', self::TOTAL_TESTS), $verify['stdout']);
        self::assertStringContainsString(sprintf('%d would replay', self::TOTAL_TESTS), $verify['stdout']);
        self::assertStringContainsString('0 divergences', $verify['stdout']);
        self::assertStringContainsString('lifetime: 0 in 1 runs', $verify['stdout']);

        $divergence = self::readJson(ReplayAssert::stateDir($this->fixture) . '/divergence.json');
        self::assertSame(1, $divergence['runs']);
        self::assertSame([], $divergence['entries']);
    }

    public function test_verify_detects_a_divergence_and_quarantines_it(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $first = $this->fixture->replay(['verify']);
        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);

        $second = $this->fixture->replay(['verify'], ['FIXTURE_FAIL' => '1']);

        self::assertSame(1, $second['exitCode'], $second['stdout'] . $second['stderr']);
        self::assertStringContainsString('✗', $second['stdout']);
        self::assertStringContainsString('1 divergences', $second['stdout']);
        self::assertStringContainsString('lifetime: 1 in 2 runs', $second['stdout']);

        $testId = 'App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet';

        $divergence = self::readJson(ReplayAssert::stateDir($this->fixture) . '/divergence.json');
        self::assertSame(2, $divergence['runs']);
        self::assertCount(1, $divergence['entries']);
        self::assertSame($testId, $divergence['entries'][0]['testId']);
        self::assertSame(0, $divergence['entries'][0]['cached']);
        self::assertSame(7, $divergence['entries'][0]['actual']);

        $flaky = self::readJson(ReplayAssert::stateDir($this->fixture) . '/flaky.json');
        self::assertArrayHasKey($testId, $flaky);
        self::assertSame('divergence', $flaky[$testId]['reason']);
        self::assertSame(1, $flaky[$testId]['flips']);

        $status = $this->fixture->replay(['status']);
        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        self::assertStringContainsString('divergences: 1 in 2 verify runs', $status['stdout']);
        self::assertStringContainsString($testId, $status['stdout']);
        self::assertStringContainsString('reason=divergence', $status['stdout']);
    }

    /**
     * Bug fix: `VerifyCommand` used to declare no `--parallel` option at all, and
     * `RunPipeline::verify()` constructed a `PhpunitProcess` directly instead of going
     * through `runPhpunit()` (the one branch that picks between `PhpunitProcess` and
     * `ParatestProcess`, SPEC.md §13) — so the flag had no effect either way. Plain
     * PHPUnit and Paratest print distinctly different banners (`PHPUnit x.y.z by ...`
     * vs. `ParaTest x.y.z upon PHPUnit x.y.z by ...` plus a `Processes:` line), which is
     * what lets this assert *which one actually ran* rather than just comparing results
     * (identical either way on this fixture).
     */
    public function test_verify_with_no_flag_stays_sequential(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $verify = $this->fixture->replay(['verify']);

        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);
        self::assertStringContainsString('PHPUnit ', $verify['stdout']);
        self::assertStringNotContainsString('ParaTest', $verify['stdout']);
    }

    public function test_verify_parallel_actually_goes_through_paratest(): void
    {
        if (! FixtureProject::paratestAvailable()) {
            self::markTestSkipped('vendor/bin/paratest is not installed in this package (composer install --no-dev?).');
        }

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $verify = $this->fixture->replay(['verify', '--parallel=2']);

        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);
        self::assertStringContainsString('ParaTest', $verify['stdout']);
        self::assertStringContainsString('Processes:     2', $verify['stdout']);
        self::assertStringContainsString(sprintf('%d tests', self::TOTAL_TESTS), $verify['stdout']);
        self::assertStringContainsString(sprintf('%d would replay', self::TOTAL_TESTS), $verify['stdout']);
        self::assertStringContainsString('0 divergences', $verify['stdout']);
    }

    /** @return array<string, mixed> */
    private static function readJson(string $path): array
    {
        self::assertFileExists($path);
        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
