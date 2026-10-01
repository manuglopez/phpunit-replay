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
    /** The directories the Laravel rules attribute, and a fallback covers for what they cannot. */
    private const ATTRIBUTED = ['database/migrations/**', 'resources/views/**'];

    /**
     * `$rulesAttribute`: the Laravel rules run (`LaravelDetector::enabled()`). A configured watch
     * pattern applies to every changed file, edges or not (`Rules\WatchRule`), which says "every
     * test depends on these directories". For `resources/views/**` and `database/migrations/**`
     * that is too broad while the Laravel rules run: a template a test renders is linked to it
     * on every render (`BladeTracker`), one a rendered template references is `BladeRule`'s,
     * and a `.php` migration is `MigrationRule`'s, by table. It is not too broad for what those
     * rules cannot see, and running more is the rule when nothing can say less: a template no
     * rendered template references statically (`vendor/pagination/*` overrides, `errors/404`,
     * a `view('pages.' . $slug)` target, the other candidate of an `@includeFirst`), a plain
     * `.php` view, a `.sql` file a migration reads. So with the rules on, those two patterns
     * are {@see self::fallbacks()}: they fire only for a changed file no rule claimed, and run
     * every test for it. With the rules off (`laravel => 'off'`) they are ordinary defaults.
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
            ...($this->rulesAttribute ? [] : self::ATTRIBUTED),
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

    /**
     * The patterns that apply only to a changed file no rule claimed (`WatchPatterns::addFallback()`).
     *
     * @param list<string> $testDirectories
     * @return array<string, list<string>>
     */
    public function fallbacks(array $testDirectories): array
    {
        return $this->rulesAttribute ? array_fill_keys(self::ATTRIBUTED, $testDirectories) : [];
    }
}
