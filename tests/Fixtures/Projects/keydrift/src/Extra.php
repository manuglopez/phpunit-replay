<?php

declare(strict_types=1);

namespace App;

/**
 * Executed by App\Tests\CoreTest only when FIXTURE_EXTRA_EDGE=1 is in the environment.
 * Nothing else in this fixture touches it, so it is the one file that can make a test
 * file's OBSERVED dependency set grow from one pass to the next without a single byte of
 * the tree changing — the mechanism behind the shrinking `would replay` figure this
 * fixture exists to reproduce (`Console\Runner\RunPipeline::verify()`).
 */
final class Extra
{
    public function triple(int $value): int
    {
        return $value * 3;
    }
}
