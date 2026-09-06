<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * SPEC.md §15 scenario 11: `--log-junit` always produces a JUnit report covering the
 * whole known suite, merging PHPUnit's own report (for whatever actually ran) with the
 * cached results for everything replayed, each marked with a `replayed="true"` property
 * (`Report\JUnitMerger`, SPEC §3.1 step 11).
 */
final class Scenario11JUnitMergeTest extends TestCase
{
    private FixtureProject $fixture;

    private string $junitDir;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
        $this->junitDir = TempDir::make('replay-junit');

        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
        TempDir::remove($this->junitDir);
    }

    public function test_log_junit_on_a_zero_executed_run_contains_every_test_marked_replayed(): void
    {
        $junitPath = $this->junitDir . '/junit.xml';

        $result = $this->fixture->replay(['--log-junit=' . $junitPath]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertFileExists($junitPath);

        $testCases = self::testCases($junitPath);
        self::assertCount(35, $testCases);

        foreach ($testCases as $testCase) {
            self::assertTrue(self::isMarkedReplayed($testCase), 'every testcase should carry replayed="true"');
        }
    }

    public function test_log_junit_on_a_partial_run_covers_real_and_replayed_tests(): void
    {
        $this->fixture->applyVariant('Money.behaviour.php', 'src/Money.php');

        $junitPath = $this->junitDir . '/junit.xml';

        $result = $this->fixture->replay(['--log-junit=' . $junitPath]);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertFileExists($junitPath);

        $testCases = self::testCases($junitPath);
        self::assertCount(35, $testCases);

        $replayed = 0;
        $real = 0;

        foreach ($testCases as $testCase) {
            if (self::isMarkedReplayed($testCase)) {
                $replayed++;
            } else {
                $real++;
            }
        }

        self::assertSame(4, $replayed, 'GreeterTest (4 tests) is unaffected and should be replayed');
        self::assertSame(31, $real);
        self::assertSame(35, $real + $replayed);
    }

    /** @return list<DOMElement> */
    private static function testCases(string $path): array
    {
        $xml = file_get_contents($path);
        self::assertIsString($xml);

        $document = new DOMDocument();
        self::assertTrue($document->loadXML($xml));

        $nodes = (new DOMXPath($document))->query('//testcase');
        self::assertNotFalse($nodes);

        $cases = [];

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $cases[] = $node;
            }
        }

        return $cases;
    }

    private static function isMarkedReplayed(DOMElement $testCase): bool
    {
        foreach ($testCase->getElementsByTagName('property') as $property) {
            if ($property instanceof DOMElement
                && $property->getAttribute('name') === 'replayed'
                && $property->getAttribute('value') === 'true'
            ) {
                return true;
            }
        }

        return false;
    }
}
