<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

/**
 * Collects, over the course of a run, the absolute paths of test files whose class uses
 * one of Laravel's database-refreshing testing traits ({@see MigrationTables::usesDatabase()}).
 * Filled by `Laravel\Subscribers\ArmLaravelTrackersOnPrepared`, flushed to
 * `<runDir>/uses_database.json` by `Laravel\Subscribers\FlushUsesDatabaseOnExecutionFinished`.
 */
final class UsesDatabaseCollector
{
    /** @var array<string, true> */
    private array $files = [];

    public function add(string $testFileAbsolute): void
    {
        if ($testFileAbsolute === '') {
            return;
        }

        $this->files[$testFileAbsolute] = true;
    }

    /** @return list<string> */
    public function all(): array
    {
        return array_keys($this->files);
    }
}
