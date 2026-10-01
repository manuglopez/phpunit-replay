<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\Rules;

use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Select\WatchPatterns;

/**
 * The watch patterns (SPEC.md §7.2.6). Files matching nothing affect nothing (§7.2.7).
 *
 * A configured pattern (a default or the project's `watch`) is **additive**: it applies to
 * every changed file, whatever an earlier rule already did with it. A pattern says "these
 * tests depend on these files"; a file some test has an edge to is not less of a dependency
 * of the others because of it. Coverage attributes a file's once-per-process work (a
 * migration run per worker, a config file loaded at boot) to whichever test got there first,
 * so letting `PhpEdgeRule` consume such a file silently dropped every other test the pattern
 * named.
 *
 * The fallback patterns (`WatchPatterns::addFallback()`) are the opposite, "nothing attributed
 * this file", so they apply only to a file no rule claimed: the `static_declaration_edges`
 * residue, and the Laravel `resources/views/**` / `database/migrations/**` defaults while the
 * Laravel rules run. A `.php` file coverage cannot see at all (`<source><exclude>`,
 * `WatchPatterns::addUnattributable()`) is additive like a configured pattern: any edge it has
 * is a name reference, which does not say who executes it.
 *
 * **Watch patterns only ever add.** The three are unioned per pattern, target lists joined, so
 * a configured key spelled exactly like a fallback (`resources/views/**` => one test) or a
 * pattern naming an unattributable file never narrows what that fallback or that file selects.
 */
final class WatchRule implements Rule
{
    public function name(): string
    {
        return 'Watch';
    }

    public function apply(Context $context): void
    {
        $allTestFiles = $context->graph->allTestFiles();
        $remaining = array_fill_keys($context->remaining, true);

        foreach ($context->changed as $rel) {
            // Unioned per key, never `+`: a user pattern spelled like a fallback default
            // (`resources/views/**` => one test) must add to the fallback's targets, not
            // replace them. Watch patterns only ever add.
            $matches = WatchPatterns::union(
                $context->watch->matches($rel),
                $context->watch->unattributableMatches($rel),
                isset($remaining[$rel]) ? $context->watch->fallbackMatches($rel) : [],
            );

            if ($matches === []) {
                continue;
            }

            foreach ($matches as $pattern => $dirs) {
                foreach ($dirs as $dir) {
                    $testFiles = $context->watch->testsUnderDirectories([$dir], $allTestFiles);

                    foreach ($testFiles as $testFile) {
                        $context->selection->add(
                            $testFile,
                            new Reason($this->name(), $rel, sprintf('%s → %s', $pattern, $dir)),
                        );
                    }
                }
            }

            if (isset($remaining[$rel])) {
                $context->consume($rel);
            }
        }
    }
}
