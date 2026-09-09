<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

/**
 * A source whose *body* genuinely runs at most once per worker process, never once per
 * test that depends on it (docs/reproducibility.md "Once-per-process residue", SPEC.md
 * §4.3.1). A coverage driver can only ever credit whichever test happened to trigger such
 * a body first in a given worker — every other test that also depends on it never gets the
 * edge, no matter how many times the suite is re-recorded, because the file's true holder
 * set is not something re-recording converges on: a test that never once happens to be the
 * first loader in any worker distribution never gets the edge to lose in the first place.
 *
 * `Cache\GraphUpdater::apply()` is the only consumer: when `$staticEdges` and an
 * implementation of this interface are both present, it refuses to record a
 * COVERAGE-derived edge to a source {@see self::matches()} accepts, landing the file in
 * `Select\ResiduePatterns`' no-`fileId` bucket instead of silently under-representing its
 * true holder set — a change to it then re-runs every test the graph knows rather than
 * only whichever one a worker happened to credit. `Analysis\StaticEdges::expand()`'s edge
 * is deliberately untouched: a test whose own source names a matching file still gets it,
 * because a name reference is order-independent evidence and coverage attribution is the
 * only half of the two edge writers that is not.
 *
 * `{@see self::matches()}` is a pure, syntactic classification over a project-relative
 * path — no filesystem access, no application boot, no framework container — because
 * *which* convention a path belongs to is the whole signal; nothing about a file's content
 * needs reading to know whether re-recording it can ever repair its holder set.
 *
 * This interface exists so that `GraphUpdater` — a generic, framework-agnostic core class
 * — depends on this abstraction instead of importing a framework adapter directly, the
 * same seam `Select\WatchDefault` and `Record\CoverageDriver` already give the core other
 * framework- and driver-specific concerns. The one shipped implementation,
 * {@see \Manuglopez\Replay\Laravel\OncePerProcessPaths}, classifies Laravel's
 * migration/seeder/console-command conventions; see its own docblock for why exactly those
 * three, and for what this deliberately does not cover.
 */
interface OnceProcessClassifier
{
    /** Whether `$relative` (project-relative, `/`-separated) is a once-per-process source. */
    public function matches(string $relative): bool;
}
