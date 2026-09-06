<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\Rules;

use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;

/**
 * A changed file known to the graph (source or deleted-but-still-linked) affects every
 * test file whose edges include it (SPEC.md §7.2.2).
 */
final class PhpEdgeRule implements Rule
{
    public function name(): string
    {
        return 'PhpEdge';
    }

    public function apply(Context $context): void
    {
        foreach ($context->remaining as $rel) {
            if ($context->graph->fileId($rel) === null) {
                continue;
            }

            foreach ($context->graph->testFilesDependingOn($rel) as $testFile) {
                $context->selection->add($testFile, new Reason($this->name(), $rel));
            }

            $context->consume($rel);
        }
    }
}
