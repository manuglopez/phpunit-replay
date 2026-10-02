<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

/**
 * Per application, whether it is building its database right now ({@see TableTracker}): how
 * many migrations are running, and whether a seeder was ever resolved from its container.
 */
final class DatabaseBuildState
{
    public int $migrating = 0;

    public bool $seeding = false;
}
