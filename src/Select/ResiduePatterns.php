<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Cache\Graph;

/**
 * The conservative half of `static_declaration_edges` (SPEC.md §4.3.1): a watch pattern per
 * changed `.php` path the graph has no edge for, pointing at every test directory.
 *
 * With the flag on, every edge in the graph is order-independent by construction
 * (`Analysis\StaticEdges`, `Record\Recorder`), which is the point — but it also means the
 * graph now *declines* to guess. A changed `.php` file with no edge is one whose relationship
 * to the suite was never established by either technique: a `lang/` or `config/` file (no
 * declared name for anything to reference), a file php-parser could not read, a class whose
 * bodies no test ever entered, a file only ever named through a string convention inside
 * vendor code. Dropping those is the false green this whole mechanism exists to remove, so
 * they are covered by the widest lever the package already has (SPEC.md §7.2.6): the file's
 * own literal path as a watch pattern mapped to every test directory, which
 * {@see Rules\WatchRule} turns into "run everything the graph knows".
 *
 * Two consequences worth stating plainly. First, this is deliberately not
 * `Cache\Fingerprint::structuralDrift` — putting these files in the structural bucket would
 * discard the *entire graph on every machine* on any translation tweak, where the watch
 * fallback re-runs the suite for that one change and leaves every edge intact. Second, it is
 * broader than strictly necessary: a brand-new `.php` file also has no edge, and running
 * everything for it is waste rather than risk (whatever now references it changed too, so
 * the rule chain had already selected the right tests). That is the price of having no
 * false-green hole, and it is only paid with the flag on.
 *
 * The `fileId() !== null` exclusion here and {@see Rules\PhpEdgeRule}'s `fileId() === null`
 * skip are exact complements, so the two mechanisms partition the changed set rather than
 * race for it.
 *
 * `.blade.php` is excluded: {@see Rules\BladeRule} and the Laravel `resources/views/**`
 * default already own that path, and its own unmatched files already fall through to
 * `WatchRule` anyway.
 *
 * Shared by {@see RunListBuilder::build()} and `Console\Commands\ExplainCommand` on purpose:
 * `explain` has to show the plan a real pass would produce, not a rosier one, and a second
 * hand-rolled copy of this predicate would drift from the first the moment either changed.
 */
final readonly class ResiduePatterns
{
    public function __construct(
        private Graph $graph,
        private TestPaths $testPaths,
    ) {
    }

    /**
     * @param list<string> $changed project-relative
     * @return array<string, list<string>> pattern (a literal path) => test directories
     */
    public function for(array $changed): array
    {
        $directories = $this->testPaths->directories();

        if ($directories === []) {
            return [];
        }

        $patterns = [];

        foreach ($changed as $rel) {
            if ($this->isResidue($rel)) {
                $patterns[$rel] = $directories;
            }
        }

        return $patterns;
    }

    public function isResidue(string $rel): bool
    {
        $lower = strtolower($rel);

        if (! str_ends_with($lower, '.php') || str_ends_with($lower, '.blade.php')) {
            return false;
        }

        return ! $this->testPaths->isTestFile($rel) && $this->graph->fileId($rel) === null;
    }
}
