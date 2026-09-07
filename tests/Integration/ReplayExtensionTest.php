<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphUpdater;
use Manuglopez\Replay\PHPUnit\ConfigurationWriter;
use Manuglopez\Replay\Record\DriverDetector;
use Manuglopez\Replay\Record\RunPartial;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end: runs the real `plain` fixture through a real `phpunit` subprocess with
 * `ReplayExtension` bootstrapped, and inspects the run partial it writes. SPEC.md
 * §15 "Integration", docs/INTERNALS.md "Extension behaviour (phase 1)".
 */
final class ReplayExtensionTest extends TestCase
{
    /** @var list<FixtureProject> */
    private array $fixtures = [];

    /** @var list<string> */
    private array $stateDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        foreach ($this->stateDirs as $stateDir) {
            TempDir::remove($stateDir);
        }

        $this->fixtures = [];
        $this->stateDirs = [];

        parent::tearDown();
    }

    public function test_record_mode_writes_edges_results_and_meta(): void
    {
        [$fixture, $stateDir, $result, $partial] = $this->runFixture('record');

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertFileExists($stateDir . '/runs/t1/edges.json');
        self::assertFileExists($stateDir . '/runs/t1/results.json');
        self::assertFileExists($stateDir . '/runs/t1/meta.json');

        self::assertArrayHasKey('tests/CartTest.php', $partial->edges);
        self::assertEqualsCanonicalizing(
            ['tests/CartTest.php', 'src/Cart.php', 'src/TaxCalculator.php', 'src/Money.php'],
            $partial->edges['tests/CartTest.php'],
        );
        self::assertNotContains('src/Greeter.php', $partial->edges['tests/CartTest.php']);

        self::assertArrayHasKey('tests/GreeterTest.php', $partial->edges);
        self::assertContains('src/Greeter.php', $partial->edges['tests/GreeterTest.php']);
        self::assertNotContains('src/Cart.php', $partial->edges['tests/GreeterTest.php']);

        self::assertCount(35, $partial->results);

        // Real PHPUnit\Event\Code\TestMethod::id() format for a named data set:
        // "Fully\Qualified\Class::method#dataSetName".
        $dataProviderId = 'App\Tests\TaxCalculatorTest::testTaxForAppliesExpectedRate#standard on 1000 cents';
        self::assertArrayHasKey($dataProviderId, $partial->results);

        $skippedId = 'App\Tests\MoneyTest::testSkippedOnPurpose';
        self::assertArrayHasKey($skippedId, $partial->results);
        self::assertSame(1, $partial->results[$skippedId]['status']);

        foreach ($partial->results as $testId => $testResult) {
            if ($testId === $skippedId) {
                continue;
            }

            self::assertSame(0, $testResult['status'], $testId . ': ' . $testResult['message']);
        }

        $passingId = 'App\Tests\CartTest::testEmptyCartHasZeroTotals';
        self::assertArrayHasKey($passingId, $partial->results);
        self::assertGreaterThan(0, $partial->results[$passingId]['assertions']);

        // Driver-agnostic: whatever coverage driver is actually loaded for this process
        // (pcov preferred, xdebug as fallback — SPEC §5.1) is what the fixture subprocess
        // records; a CI cell running under xdebug alone must not fail on a hardcoded "pcov".
        self::assertSame(DriverDetector::loadedExtension(), $partial->meta['driver']);
        self::assertFalse($partial->meta['truncated']);
        self::assertSame(1, $partial->meta['fingerprint']['structural']['schema']);

        $this->applyThroughGraphUpdater($fixture->root(), $partial);
    }

    public function test_fixture_fail_env_forces_exactly_the_greeter_test_to_fail(): void
    {
        [, , $result, $partial] = $this->runFixture('record', ['FIXTURE_FAIL' => '1']);

        self::assertSame(1, $result['exitCode']);

        $failingId = 'App\Tests\GreeterTest::testFailsWhenFixtureFailEnvIsSet';
        self::assertArrayHasKey($failingId, $partial->results);
        self::assertSame(7, $partial->results[$failingId]['status']);
        self::assertStringContainsString('FIXTURE_FAIL', $partial->results[$failingId]['message']);
    }

    public function test_results_only_mode_writes_results_without_edges(): void
    {
        [, $stateDir, $result, $partial] = $this->runFixture('results-only');

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertFileExists($stateDir . '/runs/t1/results.json');
        self::assertCount(35, $partial->results);
        self::assertSame([], $partial->edges);
    }

    public function test_record_subset_mode_only_records_the_selected_test_file(): void
    {
        $fixture = $this->plain();
        $root = $fixture->root();

        (new ConfigurationWriter())->write($root . '/phpunit.xml', ['tests/GreeterTest.php'], $root);

        [$stateDir, $result, $partial] = $this->runWithMode($fixture, 'record-subset');

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertCount(4, $partial->results);
        self::assertSame(['tests/GreeterTest.php'], array_keys($partial->edges));
        self::assertContains('src/Greeter.php', $partial->edges['tests/GreeterTest.php']);
    }

    public function test_replay_disabled_creates_no_state(): void
    {
        $fixture = $this->plain();
        (new ConfigurationWriter())->withExtensionOnly($fixture->root() . '/phpunit.xml');

        [$stateDir, $result] = $this->runRaw($fixture, 'record', ['PHPUNIT_REPLAY' => '0']);

        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertDirectoryDoesNotExist($stateDir . '/runs');
    }

    /**
     * @param  array<string, string>  $extraEnv
     * @return array{0: FixtureProject, 1: string, 2: array{exitCode: int, stdout: string, stderr: string}, 3: RunPartial}
     */
    private function runFixture(string $mode, array $extraEnv = []): array
    {
        $fixture = $this->plain();
        (new ConfigurationWriter())->withExtensionOnly($fixture->root() . '/phpunit.xml');

        [$stateDir, $result, $partial] = $this->runWithMode($fixture, $mode, $extraEnv);

        return [$fixture, $stateDir, $result, $partial];
    }

    /**
     * @param  array<string, string>  $extraEnv
     * @return array{0: string, 1: array{exitCode: int, stdout: string, stderr: string}, 2: RunPartial}
     */
    private function runWithMode(FixtureProject $fixture, string $mode, array $extraEnv = []): array
    {
        [$stateDir, $result] = $this->runRaw($fixture, $mode, $extraEnv);

        $partial = RunPartial::load($stateDir . '/runs/t1');
        self::assertNotNull($partial, 'Expected a run partial at ' . $stateDir . '/runs/t1: ' . $result['stdout'] . $result['stderr']);

        return [$stateDir, $result, $partial];
    }

    /**
     * @param  array<string, string>  $extraEnv
     * @return array{0: string, 1: array{exitCode: int, stdout: string, stderr: string}}
     */
    private function runRaw(FixtureProject $fixture, string $mode, array $extraEnv = []): array
    {
        $stateDir = TempDir::make('replay-extension-state');
        $this->stateDirs[] = $stateDir;

        $result = $fixture->phpunit(
            ['-c', '.phpunit-replay.xml', '--no-coverage'],
            [
                'PHPUNIT_REPLAY_MODE' => $mode,
                'PHPUNIT_REPLAY_STATE_DIR' => $stateDir,
                'PHPUNIT_REPLAY_RUN_ID' => 't1',
                'PHPUNIT_REPLAY_ROOT' => $fixture->root(),
                ...$extraEnv,
            ],
        );

        return [$stateDir, $result];
    }

    private function applyThroughGraphUpdater(string $root, RunPartial $partial): void
    {
        $graph = new Graph($root);
        $graph->setFingerprint($partial->meta['fingerprint']);

        $updater = new GraphUpdater($graph, $root, new ContentKey($root));
        $updater->apply($partial, 'main', recordsEdges: true, complete: true);

        self::assertTrue($graph->knowsTest('tests/CartTest.php'));

        $dependents = $graph->testFilesDependingOn('src/Money.php');
        self::assertContains('tests/CartTest.php', $dependents);
        self::assertContains('tests/MoneyTest.php', $dependents);
        self::assertContains('tests/TaxCalculatorTest.php', $dependents);
        self::assertContains('tests/DependsTest.php', $dependents);
        self::assertContains('tests/CommentedTest.php', $dependents);

        foreach ($graph->results('main') as $testId => $result) {
            self::assertArrayHasKey('key', $result, $testId . ' is missing its content key');
            self::assertNotNull($result['key'], $testId . ' has a null content key');
        }
    }

    private function plain(): FixtureProject
    {
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;

        return $fixture;
    }
}
