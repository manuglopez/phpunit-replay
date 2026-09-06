<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

/**
 * Why a test file was selected: which rule matched, which changed path triggered it,
 * and (for rules that need it) a human-readable detail for `--explain` (SPEC.md §11).
 */
final readonly class Reason
{
    public function __construct(
        public string $rule,
        public string $trigger,
        public string $detail = '',
    ) {
    }
}
