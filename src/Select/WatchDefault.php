<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

/** A framework-specific set of default watch patterns (SPEC.md §7.2.6). */
interface WatchDefault
{
    public function applicable(string $projectRoot): bool;

    /**
     * @param  list<string>  $testDirectories  project-relative
     * @return array<string, list<string>> pattern → project-relative test dirs
     */
    public function defaults(string $projectRoot, array $testDirectories): array;
}
