<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

/**
 * Laravel conventions whose *bodies* genuinely execute at most once per worker process,
 * as opposed to once per test (docs/reproducibility.md "Once-per-process residue").
 *
 * A migration runs against a worker's test database exactly once (Laravel's
 * `RefreshDatabase`/`DatabaseMigrations` testing traits guard the migrate+seed step with a
 * process-wide static flag, `RefreshDatabaseState::$migrated`); a seeder the same way; an
 * `app/Console/Commands/*` class registers itself with the Kernel once per process
 * regardless of how many tests touch it. None of that is a coverage-instrumentation
 * artifact the way the declaration/first-loader problem is ({@see \Manuglopez\Replay\Analysis\StaticEdges}
 * class docblock) — it is the *actual* application semantics, so no amount of careful
 * recording ever gets a second test's coverage to show the line as touched. Whichever test
 * happened to run first in that worker is credited; every other test that also depends on
 * the file is not, and the graph's edge set (SPEC.md §4.3.1 "static_declaration_edges")
 * quietly under-represents that file's true holder set. `Cache\GraphUpdater::apply()` uses
 * {@see self::matches()} to refuse recording that COVERAGE-derived edge at all for a file
 * under one of these paths, landing it in {@see \Manuglopez\Replay\Select\ResiduePatterns}'
 * no-`fileId` bucket instead — see that class's docblock for why "no edge" is the safe
 * outcome only when `static_declaration_edges` is on, which is why `GraphUpdater` gates the
 * refusal on that flag rather than always.
 *
 * ## Why exactly these three, and only these
 *
 * Migrations (`database/migrations`), seeders (`database/seeders`) and console commands
 * (`app/Console/Commands`) are the shapes the measured false-green window
 * (docs/reproducibility.md) actually found, and they share the property that makes a purely
 * *syntactic* (no application boot required) classification sound: the convention is the
 * whole signal. A file at `database/migrations/2024_01_01_000000_create_users_table.php` is
 * a migration because Laravel's migrator discovers migrations by walking that directory,
 * full stop — nothing about the file's own content needs reading to know it runs at most
 * once per process.
 *
 * `app/Services/*`, factories and models are deliberately NOT here, and the measured false
 * green for those (2 + 2 of the 22 movers) is not closed by this class: a service class
 * might be a singleton that only initialises once, or might not be — the path alone does
 * not say so, and guessing wrong in the "still gets an edge" direction would silently
 * reintroduce the very bug this exists to remove. Closing that hole needs a genuinely
 * different, semantic signal (docs/reproducibility.md records the ones considered and why
 * none shipped), not a fourth convention path bolted on here.
 *
 * ## What this deliberately does not do
 *
 * No live Illuminate container is ever consulted here — not `app('migrator')->paths()`, not
 * a custom `databasePath()` — even though `Cache\GraphUpdater::apply()` sometimes runs
 * in-process, with Laravel already booted ({@see \Manuglopez\Replay\PHPUnit\ReplayState::persistInProcess()}),
 * and sometimes in the wrapper process, with no Illuminate loaded at all
 * ({@see \Manuglopez\Replay\Console\Runner\RunPipeline}) — see {@see LaravelDetector}'s own
 * docblock for that split. Asking the container would only ever be reachable from the
 * in-process half, so the same migration file would classify differently depending on which
 * of the two recorded it — exactly the process/environment-shape-dependent non-determinism
 * this whole feature exists to remove, reintroduced one level up. A project with an
 * unconventional layout (e.g. `Modules/*\/Database/Migrations`, a package that registers its
 * own migration path) therefore keeps today's behaviour: those files are unknown to this
 * class, so `Cache\GraphUpdater` records their coverage-derived edge exactly as before, and
 * the shrinking-holder-set false green documented in docs/reproducibility.md still applies
 * to them. That is a known limitation, not an oversight — see docs/reproducibility.md.
 *
 * No user-facing configuration key exists for any of this, deliberately: extending or
 * narrowing the convention list is a code change, not a project setting (docs/reproducibility.md).
 */
final class OncePerProcessPaths
{
    /**
     * Project-relative directory prefixes, each trailing with `/` so a sibling like
     * `database/migrations-backup/` can never match. Order is irrelevant: {@see self::matches()}
     * checks every one.
     *
     * @var list<string>
     */
    private const PREFIXES = [
        'database/migrations/',
        'database/seeders/',
        'app/Console/Commands/',
    ];

    /** Whether `$relative` (project-relative, `/`-separated) is a once-per-process convention path. */
    public function matches(string $relative): bool
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
