<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

use DOMDocument;
use DOMElement;
use Manuglopez\Replay\Support\Paths;

/**
 * Merges cached ("replayed") results into a real JUnit XML report so `--log-junit` reflects
 * the whole suite, not just what actually ran (SPEC §3.1 step 11).
 *
 * The real report (when given) is parsed as-is and never modified; a single extra
 * `<testsuite name="phpunit-replay (replayed)">` is appended with one `<testcase>` per
 * replayed result, each carrying `<properties><property name="replayed" value="true"/></properties>`.
 *
 * Never throws: a missing or invalid `$realJunitXml` starts from an empty `<testsuites/>`
 * document instead (docs/INTERNALS.md).
 */
final readonly class JUnitMerger
{
    /**
     * @param array<string, array{status: int, message: string, time: float, assertions: int, file?: string, key?: string}> $replayed
     */
    public function merge(?string $realJunitXml, array $replayed, string $projectRoot): string
    {
        $document = self::parseOrEmpty($realJunitXml);
        $root = self::rootTestSuites($document);

        $testSuite = $document->createElement('testsuite');
        $testSuite->setAttribute('name', 'phpunit-replay (replayed)');

        $tests = 0;
        $assertions = 0;
        $skipped = 0;
        $time = 0.0;

        foreach ($replayed as $testId => $result) {
            $status = $result['status'];

            // Defensive: failures (7) and errors (8) must never be replayed. Skip and count nothing.
            if ($status === 7 || $status === 8) {
                continue;
            }

            $testSuite->appendChild(self::buildTestCase($document, $testId, $result, $projectRoot));

            $tests++;
            $assertions += $result['assertions'];
            $time += $result['time'];

            if ($status === 1 || $status === 2) {
                $skipped++;
            }
        }

        $testSuite->setAttribute('tests', (string) $tests);
        $testSuite->setAttribute('assertions', (string) $assertions);
        $testSuite->setAttribute('errors', '0');
        $testSuite->setAttribute('failures', '0');
        $testSuite->setAttribute('skipped', (string) $skipped);
        $testSuite->setAttribute('time', sprintf('%F', $time));

        $root->appendChild($testSuite);

        $document->formatOutput = true;

        $xml = $document->saveXML();

        return $xml === false ? '' : $xml;
    }

    /**
     * @param array{status: int, message: string, time: float, assertions: int, file?: string, key?: string} $result
     */
    private static function buildTestCase(DOMDocument $document, string $testId, array $result, string $projectRoot): DOMElement
    {
        [$class, $rest] = self::splitTestId($testId);
        [$method, $dataSetName] = self::splitDataSetName($rest);

        $testCase = $document->createElement('testcase');
        $testCase->setAttribute('name', self::testCaseName($method, $dataSetName));

        if ($class !== null) {
            $testCase->setAttribute('class', $class);
            $testCase->setAttribute('classname', str_replace('\\', '.', $class));
        }

        $file = $result['file'] ?? null;

        if ($file !== null && $file !== '') {
            $testCase->setAttribute('file', Paths::join($projectRoot, $file));
        }

        $testCase->setAttribute('assertions', (string) $result['assertions']);
        $testCase->setAttribute('time', sprintf('%F', $result['time']));

        $properties = $document->createElement('properties');
        $property = $document->createElement('property');
        $property->setAttribute('name', 'replayed');
        $property->setAttribute('value', 'true');
        $properties->appendChild($property);
        $testCase->appendChild($properties);

        self::appendStatusElement($document, $testCase, $result['status'], $result['message']);

        return $testCase;
    }

    /**
     * status 1 (skipped) → plain `<skipped/>`; status 2 (incomplete) → `<skipped message="...">`,
     * defaulting to "incomplete" when there is no cached message; statuses 0/3/4/5/6
     * (success/notice/deprecation/risky/warning) have no JUnit representation of their own and
     * are left as a plain passed `<testcase>`.
     */
    private static function appendStatusElement(DOMDocument $document, DOMElement $testCase, int $status, string $message): void
    {
        if ($status === 1) {
            $testCase->appendChild($document->createElement('skipped'));

            return;
        }

        if ($status === 2) {
            $skippedElement = $document->createElement('skipped');
            $skippedElement->setAttribute('message', $message !== '' ? $message : 'incomplete');
            $testCase->appendChild($skippedElement);
        }
    }

    /**
     * Mimics `PHPUnit\Logging\JUnit\JunitXmlLogger::name()`
     * (vendor/phpunit/phpunit/src/Logging/JUnit/JunitXmlLogger.php:421-445): no dataset →
     * the method name as-is; a purely numeric dataset (`#3`) → `method with data set #3`;
     * any other dataset (`#premium`) → `method with data set "premium"`.
     */
    private static function testCaseName(string $method, ?string $dataSetName): string
    {
        if ($dataSetName === null) {
            return $method;
        }

        if (preg_match('/^\d+$/', $dataSetName) === 1) {
            return sprintf('%s with data set #%d', $method, (int) $dataSetName);
        }

        return sprintf('%s with data set "%s"', $method, $dataSetName);
    }

    /**
     * Splits a `PHPUnit\Event\Code\TestMethod::id()`-shaped id (`Fully\Qualified\Class::method`,
     * or `Class::method#datasetName` / `Class::method#0` with a data provider — SPEC.md:146)
     * into its class and `method[#dataset]` parts. A malformed id with no `::` is treated as
     * having no class.
     *
     * @return array{0: ?string, 1: string}
     */
    private static function splitTestId(string $testId): array
    {
        $separator = strpos($testId, '::');

        if ($separator === false) {
            return [null, $testId];
        }

        return [substr($testId, 0, $separator), substr($testId, $separator + 2)];
    }

    /** @return array{0: string, 1: ?string} */
    private static function splitDataSetName(string $rest): array
    {
        $hash = strpos($rest, '#');

        if ($hash === false) {
            return [$rest, null];
        }

        return [substr($rest, 0, $hash), substr($rest, $hash + 1)];
    }

    private static function rootTestSuites(DOMDocument $document): DOMElement
    {
        $root = $document->documentElement;

        if ($root !== null) {
            return $root;
        }

        $root = $document->createElement('testsuites');
        $document->appendChild($root);

        return $root;
    }

    private static function parseOrEmpty(?string $xml): DOMDocument
    {
        if ($xml !== null && trim($xml) !== '') {
            $document = new DOMDocument('1.0', 'UTF-8');
            $previous = libxml_use_internal_errors(true);

            try {
                $loaded = $document->loadXML($xml);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }

            if ($loaded) {
                return $document;
            }
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadXML('<?xml version="1.0" encoding="UTF-8"?><testsuites/>');

        return $document;
    }
}
