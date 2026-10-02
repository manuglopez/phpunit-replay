<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * Laravel-only rule (SPEC.md §7.2.4): a new/unknown-to-the-graph `.php` file under one of the
 * "sibling" directories (`app/Providers`, `app/Listeners`, `app/Events`, `app/Observers`,
 * `app/Policies`, `app/Console/Commands`, `database/factories`, `database/seeders`) affects
 * every test file with an edge to another file in the same directory — a new class in a
 * directory full of already-tested siblings is presumed exercised the same way they are.
 * A new file whose directory has no such file (a new `app/Console/Commands/Reports/`) is
 * presumed exercised like the files under the nearest ancestor directory, up to and
 * including the sibling root, that has one: it selects every test file with an edge
 * anywhere under that ancestor ({@see self::nearestAncestorWithEdges()}), and no further up.
 */
final class SiblingRule implements Rule
{
    private const PREFIXES = [
        'app/Providers/',
        'app/Listeners/',
        'app/Events/',
        'app/Observers/',
        'app/Policies/',
        'app/Console/Commands/',
        'database/factories/',
        'database/seeders/',
    ];

    /** @var array<string, true>|null per apply(): {@see self::dependencyTree()} */
    private ?array $dependencyTree = null;

    public function name(): string
    {
        return 'Sibling';
    }

    public function apply(Context $context): void
    {
        $graph = $context->graph;
        $this->dependencyTree = null;

        foreach ($context->remaining as $rel) {
            if ($graph->fileId($rel) !== null) {
                continue;
            }

            if (! self::isSiblingCandidate($rel)) {
                continue;
            }

            if (! is_file(Paths::join($context->projectRoot, $rel))) {
                continue;
            }

            $dir = dirname($rel);
            $matched = false;

            foreach ($graph->allTestFiles() as $testFile) {
                foreach ($graph->dependenciesOf($testFile) as $dependency) {
                    if (dirname($dependency) === $dir) {
                        $context->selection->add($testFile, new Reason($this->name(), $rel, $dir));
                        $matched = true;

                        break;
                    }
                }
            }

            // A new subdirectory has no tested sibling yet: the nearest ancestor, up to the
            // sibling root, under which some test has an edge stands in for it, recursively.
            $ancestor = $matched ? null : self::nearestAncestorWithEdges($rel, $this->dependencyDirectories($graph));

            if ($ancestor !== null) {
                foreach ($graph->allTestFiles() as $testFile) {
                    foreach ($graph->dependenciesOf($testFile) as $dependency) {
                        if (str_starts_with($dependency, $ancestor . '/')) {
                            $context->selection->add($testFile, new Reason($this->name(), $rel, $ancestor . '/**'));

                            break;
                        }
                    }
                }
            }

            // The ancestor walk only adds, and never consumes: a presumption one level removed
            // must not narrow what the residue fallback (with `static_declaration_edges`)
            // would have run for the file.
            //
            // Only consume when the presumption actually found a sibling to stand on, the
            // way {@see BladeRule} does with its ancestors. A directory none of whose files
            // any test has an edge to tells us nothing, and swallowing the path there hid it
            // from {@see WatchRule} — which is the only rule that would have covered it,
            // since the Laravel watch default for `app/` is `app/** !*.php` and excludes
            // exactly these files. With `static_declaration_edges` on that also silently
            // consumed the conservative residue pattern
            // ({@see \Manuglopez\Replay\Select\ResiduePatterns}).
            if ($matched) {
                $context->consume($rel);
            }
        }
    }

    /**
     * For a candidate whose own directory holds no file any test has an edge to: the nearest
     * strict ancestor of that directory, up to and including the sibling root it is under,
     * that has such a file somewhere below it. Null when none has, or when the candidate sits
     * in the root itself. Shared with `Select\NonEdgeInputs`, whose `sibling-tree:<dir>@1`
     * scopes are this step's claim.
     *
     * @param array<string, true> $dependencyDirectories every directory holding a test's
     *        dependency, and every ancestor of one ({@see self::dependencyTree()})
     */
    public static function nearestAncestorWithEdges(string $rel, array $dependencyDirectories): ?string
    {
        $root = self::rootOf($rel);

        if ($root === null) {
            return null;
        }

        $dir = dirname($rel);

        while ($dir !== $root && str_starts_with($dir, $root . '/')) {
            $dir = dirname($dir);

            if (isset($dependencyDirectories[$dir])) {
                return $dir;
            }
        }

        return null;
    }

    /**
     * Every directory holding a dependency of some test file, and each of its ancestors.
     *
     * @return array<string, true>
     */
    public static function dependencyTree(Graph $graph): array
    {
        $dirs = [];

        foreach ($graph->allTestFiles() as $testFile) {
            foreach ($graph->dependenciesOf($testFile) as $dependency) {
                for ($dir = dirname($dependency); $dir !== '.' && $dir !== '/' && ! isset($dirs[$dir]); $dir = dirname($dir)) {
                    $dirs[$dir] = true;
                }
            }
        }

        return $dirs;
    }

    /** @return array<string, true> */
    private function dependencyDirectories(Graph $graph): array
    {
        return $this->dependencyTree ??= self::dependencyTree($graph);
    }

    private static function rootOf(string $rel): ?string
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return rtrim($prefix, '/');
            }
        }

        return null;
    }

    /** Shared with `Select\NonEdgeInputs`, whose `sibling:<dir>@3` scopes are this rule's claim. */
    public static function isSiblingCandidate(string $rel): bool
    {
        if (! str_ends_with($rel, '.php')) {
            return false;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
