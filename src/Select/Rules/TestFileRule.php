<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\Rules;

use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * A changed file that is itself a test file (per TestPaths) and still exists on disk
 * affects itself (SPEC.md §7.2.3).
 */
final class TestFileRule implements Rule
{
    public function name(): string
    {
        return 'TestFile';
    }

    public function apply(Context $context): void
    {
        foreach ($context->remaining as $rel) {
            if (! $context->testPaths->isTestFile($rel)) {
                continue;
            }

            if (! is_file(Paths::join($context->projectRoot, $rel))) {
                continue;
            }

            $context->selection->add($rel, new Reason($this->name(), $rel));
            $context->consume($rel);
        }
    }
}
