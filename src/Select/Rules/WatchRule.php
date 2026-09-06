<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\Rules;

use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;

/**
 * Whatever is left and unknown to the graph is matched against the watch patterns
 * (SPEC.md §7.2.6). Files matching nothing affect nothing (SPEC.md §7.2.7).
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

        foreach ($context->remaining as $rel) {
            $matches = $context->watch->matches($rel);

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

            $context->consume($rel);
        }
    }
}
