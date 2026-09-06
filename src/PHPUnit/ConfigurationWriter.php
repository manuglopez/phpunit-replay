<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Generates the filtered PHPUnit configuration used by the wrapper's filtered mode
 * (SPEC.md §3.1 step 6). Loads the user's `phpunit.xml`/`phpunit.xml.dist` as a DOM,
 * optionally replaces `<testsuites>`/top-level `<testsuite>` with a single
 * `<testsuite name="phpunit-replay">` listing the given test files, injects
 * `<extensions><bootstrap class="Manuglopez\Replay\PHPUnit\ReplayExtension"/></extensions>`
 * when it is not already present, and writes the result next to the source file as
 * `.phpunit-replay.xml` — same directory, so every other relative path in the file
 * (bootstrap, cacheDirectory, source includes, ...) keeps resolving correctly.
 */
final class ConfigurationWriter
{
    public const TEMP_BASENAME = '.phpunit-replay.xml';

    private const EXTENSION_CLASS = 'Manuglopez\\Replay\\PHPUnit\\ReplayExtension';

    /**
     * Replaces the test selection with exactly `$testFiles` and injects the extension.
     *
     * @param  list<string>  $testFiles  project-relative
     */
    public function write(string $sourceXml, array $testFiles, string $projectRoot): string
    {
        $dom = $this->load($sourceXml);

        $this->replaceTestSuites($dom, $testFiles, $projectRoot, dirname($sourceXml));
        $this->ensureExtensionBootstrap($dom);

        return $this->save($dom, $sourceXml);
    }

    /** Keeps the original test selection untouched; only injects the extension. */
    public function withExtensionOnly(string $sourceXml): string
    {
        $dom = $this->load($sourceXml);

        $this->ensureExtensionBootstrap($dom);

        return $this->save($dom, $sourceXml);
    }

    public static function tempPath(string $sourceXml): string
    {
        return rtrim(dirname($sourceXml), '/') . '/' . self::TEMP_BASENAME;
    }

    private function load(string $sourceXml): DOMDocument
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        if (! @$dom->load($sourceXml)) {
            throw new RuntimeException('Cannot load PHPUnit configuration file: ' . $sourceXml);
        }

        return $dom;
    }

    private function save(DOMDocument $dom, string $sourceXml): string
    {
        $path = self::tempPath($sourceXml);

        if (@$dom->save($path) === false) {
            throw new RuntimeException('Cannot write generated PHPUnit configuration file: ' . $path);
        }

        return $path;
    }

    /** @param  list<string>  $testFiles  project-relative */
    private function replaceTestSuites(DOMDocument $dom, array $testFiles, string $projectRoot, string $xmlDir): void
    {
        $root = $dom->documentElement;

        if ($root === null) {
            return;
        }

        foreach ($this->childrenNamed($root, ['testsuites', 'testsuite']) as $node) {
            $root->removeChild($node);
        }

        $testSuites = $dom->createElement('testsuites');
        $testSuite = $dom->createElement('testsuite');
        $testSuite->setAttribute('name', 'phpunit-replay');

        foreach ($testFiles as $testFile) {
            $relative = $this->relativeToXmlDir($testFile, $projectRoot, $xmlDir);
            $testSuite->appendChild($dom->createElement('file', $relative));
        }

        $testSuites->appendChild($testSuite);
        $root->appendChild($testSuites);
    }

    private function ensureExtensionBootstrap(DOMDocument $dom): void
    {
        $root = $dom->documentElement;

        if ($root === null) {
            return;
        }

        $xpath = new DOMXPath($dom);
        $bootstraps = $xpath->query('//extensions/bootstrap[@class]');

        if ($bootstraps !== false) {
            foreach ($bootstraps as $bootstrap) {
                if ($bootstrap instanceof DOMElement && $bootstrap->getAttribute('class') === self::EXTENSION_CLASS) {
                    return;
                }
            }
        }

        $extensions = $this->firstElement($xpath, '//extensions');

        if ($extensions === null) {
            $extensions = $dom->createElement('extensions');
            $root->appendChild($extensions);
        }

        $bootstrap = $dom->createElement('bootstrap');
        $bootstrap->setAttribute('class', self::EXTENSION_CLASS);
        $extensions->appendChild($bootstrap);
    }

    private function firstElement(DOMXPath $xpath, string $query): ?DOMElement
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * @param  list<string>  $names
     * @return list<DOMElement>
     */
    private function childrenNamed(DOMElement $parent, array $names): array
    {
        $matches = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && in_array($child->localName, $names, true)) {
                $matches[] = $child;
            }
        }

        return $matches;
    }

    /** $testFileProjectRelative is relative to $projectRoot; the result is relative to $xmlDir. */
    private function relativeToXmlDir(string $testFileProjectRelative, string $projectRoot, string $xmlDir): string
    {
        $root = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $dir = rtrim(str_replace('\\', '/', $xmlDir), '/');

        if ($dir === $root) {
            return $testFileProjectRelative;
        }

        $absolute = $root . '/' . ltrim($testFileProjectRelative, '/');

        return self::relativePath($dir, $absolute);
    }

    private static function relativePath(string $fromDir, string $toPath): string
    {
        $isEmpty = static fn (string $part): bool => $part !== '';

        $fromParts = array_values(array_filter(explode('/', $fromDir), $isEmpty));
        $toParts = array_values(array_filter(explode('/', $toPath), $isEmpty));

        $max = min(count($fromParts), count($toParts));
        $i = 0;

        while ($i < $max && $fromParts[$i] === $toParts[$i]) {
            $i++;
        }

        $up = array_fill(0, count($fromParts) - $i, '..');
        $down = array_slice($toParts, $i);

        $segments = array_merge($up, $down);

        return $segments === [] ? '.' : implode('/', $segments);
    }
}
