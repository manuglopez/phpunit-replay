<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

/**
 * The run mode the extension operates in, decided by the wrapper (or, absent the
 * wrapper, read straight from the `PHPUNIT_REPLAY_MODE` environment variable).
 * SPEC.md §6.1.
 */
enum Mode: string
{
    case Record = 'record';
    case RecordSubset = 'record-subset';
    case ResultsOnly = 'results-only';
    case Replay = 'replay';
    case Off = 'off';

    /** Whether this mode records dependency edges (as opposed to results only). */
    public function recordsEdges(): bool
    {
        return $this === self::Record || $this === self::RecordSubset;
    }

    /** null when $value is null, empty, or does not match a known mode. */
    public static function tryFromEnv(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
