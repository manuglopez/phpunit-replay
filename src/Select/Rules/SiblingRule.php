<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\Rules;

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

    public function name(): string
    {
        return 'Sibling';
    }

    public function apply(Context $context): void
    {
        $graph = $context->graph;

        foreach ($context->remaining as $rel) {
            if ($graph->fileId($rel) !== null) {
                continue;
            }

            if (! $this->isSiblingCandidate($rel)) {
                continue;
            }

            if (! is_file(Paths::join($context->projectRoot, $rel))) {
                continue;
            }

            $dir = dirname($rel);

            foreach ($graph->allTestFiles() as $testFile) {
                foreach ($graph->dependenciesOf($testFile) as $dependency) {
                    if (dirname($dependency) === $dir) {
                        $context->selection->add($testFile, new Reason($this->name(), $rel, $dir));

                        break;
                    }
                }
            }

            $context->consume($rel);
        }
    }

    private function isSiblingCandidate(string $rel): bool
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
