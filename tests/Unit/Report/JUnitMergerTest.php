<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Report;

use DOMDocument;
use DOMXPath;
use Manuglopez\Replay\Report\JUnitMerger;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;

final class JUnitMergerTest extends TestCase
{
    private const REAL_TEST_COUNT = 35;

    /** @var list<FixtureProject> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        $this->fixtures = [];
    }

    public function testMergeAppendsAReplayedTestsuiteWithoutTouchingTheRealOne(): void
    {
        $fixture = $this->plain();
        $result = $fixture->phpunit(['--log-junit', 'junit.xml']);

        self::assertSame(0, $result['exitCode']);

        $realJunitXml = $fixture->read('junit.xml');

        $merger = new JUnitMerger();
        $merged = $merger->merge($realJunitXml, $this->replayed(), $fixture->root());

        $xpath = $this->xpathFor($merged);

        // The real testsuite tree is untouched: every real testcase is still there.
        self::assertSame(
            self::REAL_TEST_COUNT,
            $xpath->query('//testsuite[@name!="phpunit-replay (replayed)"]//testcase')->length,
        );

        $replayedSuites = $xpath->query('//testsuite[@name="phpunit-replay (replayed)"]');
        self::assertSame(1, $replayedSuites->length);

        $replayedSuite = $replayedSuites->item(0);
        self::assertNotNull($replayedSuite);
        self::assertSame('2', $replayedSuite->attributes?->getNamedItem('tests')?->nodeValue);
        self::assertSame('1', $replayedSuite->attributes?->getNamedItem('skipped')?->nodeValue);
        self::assertSame('0', $replayedSuite->attributes?->getNamedItem('errors')?->nodeValue);
        self::assertSame('0', $replayedSuite->attributes?->getNamedItem('failures')?->nodeValue);
        self::assertSame('3', $replayedSuite->attributes?->getNamedItem('assertions')?->nodeValue);

        // Both replayed testcases are marked with <properties><property name="replayed" value="true"/></properties>.
        self::assertSame(
            2,
            $xpath->query('//testsuite[@name="phpunit-replay (replayed)"]/testcase/properties/property[@name="replayed" and @value="true"]')->length,
        );

        // The named data set is rendered the way PHPUnit's own JunitXmlLogger::name() does.
        $passedTestCases = $xpath->query(
            '//testsuite[@name="phpunit-replay (replayed)"]/testcase[@name=\'testTaxForAppliesExpectedRate with data set "premium"\']',
        );
        self::assertSame(1, $passedTestCases->length);

        $passedTestCase = $passedTestCases->item(0);
        self::assertNotNull($passedTestCase);
        self::assertSame('App\Tests\TaxCalculatorTest', $passedTestCase->attributes?->getNamedItem('class')?->nodeValue);
        self::assertSame('App.Tests.TaxCalculatorTest', $passedTestCase->attributes?->getNamedItem('classname')?->nodeValue);
        self::assertSame($fixture->root() . '/tests/TaxCalculatorTest.php', $passedTestCase->attributes?->getNamedItem('file')?->nodeValue);
        self::assertNull($xpath->query('skipped', $passedTestCase)->item(0));

        $skippedTestCases = $xpath->query(
            '//testsuite[@name="phpunit-replay (replayed)"]/testcase[@name="testAlwaysSkippedInThisReplayCache"]',
        );
        self::assertSame(1, $skippedTestCases->length);

        $skippedTestCase = $skippedTestCases->item(0);
        self::assertNotNull($skippedTestCase);
        $skippedElement = $xpath->query('skipped', $skippedTestCase)->item(0);
        self::assertNotNull($skippedElement);
    }

    public function testMergeWithNullRealJunitStartsFromAnEmptyDocument(): void
    {
        $merger = new JUnitMerger();
        $merged = $merger->merge(null, $this->replayed(), '/project');

        $xpath = $this->xpathFor($merged);

        self::assertSame('testsuites', $xpath->document->documentElement?->nodeName);
        self::assertSame(1, $xpath->query('/testsuites/testsuite')->length);
        self::assertSame('2', $xpath->query('//testsuite[@name="phpunit-replay (replayed)"]')->item(0)?->attributes?->getNamedItem('tests')?->nodeValue);
    }

    public function testMergeWithInvalidXmlStartsFromAnEmptyDocument(): void
    {
        $merger = new JUnitMerger();
        $merged = $merger->merge('<not-valid-xml<<<', $this->replayed(), '/project');

        $xpath = $this->xpathFor($merged);

        self::assertSame('testsuites', $xpath->document->documentElement?->nodeName);
        self::assertSame(1, $xpath->query('//testsuite[@name="phpunit-replay (replayed)"]')->length);
    }

    public function testMergeNeverIncludesFailedOrErroredResults(): void
    {
        $merger = new JUnitMerger();
        $merged = $merger->merge(null, [
            'App\Tests\MoneyTest::testWouldNeverBeReplayed' => [
                'status' => 7,
                'message' => 'should never appear',
                'time' => 0.1,
                'assertions' => 1,
            ],
            'App\Tests\MoneyTest::testAlsoWouldNeverBeReplayed' => [
                'status' => 8,
                'message' => 'should never appear either',
                'time' => 0.1,
                'assertions' => 1,
            ],
        ], '/project');

        $xpath = $this->xpathFor($merged);
        $suite = $xpath->query('//testsuite[@name="phpunit-replay (replayed)"]')->item(0);

        self::assertNotNull($suite);
        self::assertSame('0', $suite->attributes?->getNamedItem('tests')?->nodeValue);
        self::assertSame(0, $xpath->query('testcase', $suite)->length);
    }

    /**
     * @return array<string, array{status: int, message: string, time: float, assertions: int, file?: string}>
     */
    private function replayed(): array
    {
        return [
            'App\Tests\TaxCalculatorTest::testTaxForAppliesExpectedRate#premium' => [
                'status' => 0,
                'message' => '',
                'time' => 0.001234,
                'assertions' => 2,
                'file' => 'tests/TaxCalculatorTest.php',
            ],
            'App\Tests\MoneyTest::testAlwaysSkippedInThisReplayCache' => [
                'status' => 1,
                'message' => '',
                'time' => 0.0,
                'assertions' => 1,
                'file' => 'tests/MoneyTest.php',
            ],
        ];
    }

    private function xpathFor(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml);

        self::assertTrue($loaded, 'merged output must be valid XML');

        return new DOMXPath($document);
    }

    private function plain(): FixtureProject
    {
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;

        return $fixture;
    }
}
