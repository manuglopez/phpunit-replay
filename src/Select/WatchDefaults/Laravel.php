<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select\WatchDefaults;

use Manuglopez\Replay\Select\WatchDefault;
use Manuglopez\Replay\Support\Paths;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/WatchDefaults/Laravel.php
 *
 * Applicable when the project has an `artisan` file (detection by file, not by
 * Composer InstalledVersions — SPEC.md §7.2.6). Every pattern maps to every test directory.
 */
final class Laravel implements WatchDefault
{
    /**
     * `$rulesAttribute`: the Laravel rules run (`LaravelDetector::enabled()`). A watch pattern
     * applies to every changed file, edges or not (`Rules\WatchRule`), so these defaults say
     * "every test depends on these directories". That is the right fallback for what nothing
     * attributes, and wrong for two directories the Laravel rules DO attribute, per test and on
     * every run: templates (`BladeTracker` links every render, `BladeRule` every static include
     * of a rendered template) and migrations (`MigrationRule`, by the tables each test's queries
     * touched; a migration it cannot read tables from runs everything, which is what the
     * `database/migrations/**` default used to do for it). With the rules on, those two defaults
     * would turn every view or migration edit into a full run; with them off (`laravel =>
     * 'off'`), nothing else covers them and the defaults stay.
     */
    public function __construct(private readonly bool $rulesAttribute = false)
    {
    }

    public function applicable(string $projectRoot): bool
    {
        return is_file(Paths::join($projectRoot, 'artisan'));
    }

    /** @param list<string> $testDirectories @return array<string, list<string>> */
    public function defaults(string $projectRoot, array $testDirectories): array
    {
        $patterns = [
            'config/**',
            'routes/**',
            ...($this->rulesAttribute ? [] : ['database/migrations/**', 'resources/views/**']),
            'lang/**',
            'resources/lang/**',
            'app/** !*.php',
            'bootstrap/*.php',
        ];

        $result = [];

        foreach ($patterns as $pattern) {
            $result[$pattern] = $testDirectories;
        }

        return $result;
    }
}
