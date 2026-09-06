<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

/** One link of the Selector rule chain (SPEC.md §7.2). */
interface Rule
{
    public function name(): string;

    public function apply(Context $context): void;
}
