<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Analysis;

use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Throwable;

/**
 * Parses one PHP source file into {@see FileFacts}.
 *
 * `nikic/php-parser` is a transitive dependency of `phpunit/php-code-coverage` on every
 * supported line, but this package declares it explicitly in `composer.json` rather than
 * relying on that: the classifier is the one place that would break silently if
 * php-code-coverage ever dropped it.
 *
 * Never throws. A file that cannot be read, or that php-parser rejects, yields
 * {@see FileFacts::unparseable()} — a positive "nothing is known", which every caller
 * turns into its pre-existing behaviour rather than into an absence of dependencies.
 */
final class DeclarationScanner
{
    /**
     * The version of the classification RULES, not of the payload shape.
     *
     * {@see FactsCache} is keyed by file content, which is the right key for "has this file
     * changed" and the wrong one for "have we changed our mind about what this file means".
     * Change anything about how {@see FactsVisitor} decides a body range or a reference —
     * as the empty-concrete-body fix did — and every cached entry on every machine keeps
     * serving the old answer for files that did not change, forever. That is a silent stale
     * classification, which is the same shape of bug this whole mechanism exists to remove.
     *
     * So: bump this whenever the rules move. It only invalidates `<stateDir>/analysis/`,
     * which costs one re-parse per file, and never the graph.
     */
    public const RULES_VERSION = 1;

    private ?Parser $parser = null;

    public function scan(string $absoluteFile): FileFacts
    {
        $source = @file_get_contents($absoluteFile);

        if ($source === false) {
            return FileFacts::unparseable();
        }

        return $this->scanSource($source);
    }

    public function scanSource(string $source): FileFacts
    {
        try {
            $statements = $this->parser()->parse($source);
        } catch (Throwable) {
            return FileFacts::unparseable();
        }

        if ($statements === null) {
            return FileFacts::unparseable();
        }

        $visitor = new FactsVisitor();

        try {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver());
            $traverser->addVisitor($visitor);
            $traverser->traverse($statements);
        } catch (Throwable) {
            return FileFacts::unparseable();
        }

        $bodies = $visitor->bodies;
        sort($bodies);

        $declares = array_keys($visitor->declares);
        sort($declares);

        $references = array_keys($visitor->references());
        sort($references);

        return new FileFacts(true, $bodies, $declares, $references);
    }

    private function parser(): Parser
    {
        return $this->parser ??= (new ParserFactory())->createForHostVersion();
    }
}
