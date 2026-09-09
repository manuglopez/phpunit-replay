<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

/**
 * The FQCN a framework adapter wants Paratest's own worker processes to run under — passed
 * through verbatim as `--runner=<class>` (SPEC.md §13) — so that whatever per-worker
 * isolation that framework needs gets wired up without this generic namespace ever naming
 * the framework, or the class, itself. The same seam {@see \Manuglopez\Replay\Select\WatchDefault},
 * {@see \Manuglopez\Replay\Record\CoverageDriver} and {@see \Manuglopez\Replay\Cache\OnceProcessClassifier}
 * already give this package's core for other framework- and driver-specific concerns.
 *
 * {@see ParatestProcess} is the only consumer, and treats a `null` collaborator (no instance
 * injected at all) and an injected instance whose own {@see self::runnerClass()} returns
 * `null` identically: no `--runner` flag, exactly today's plain `vendor/bin/paratest`
 * behaviour. Deciding WHETHER this project should receive a contributing instance at all —
 * for the one shipped implementation, {@see \Manuglopez\Replay\Laravel\ParallelIsolation},
 * that means Laravel detected, the config opt-in, Paratest present, and the project's own
 * runner class actually resolvable — is entirely the caller's job
 * ({@see \Manuglopez\Replay\Console\Runner\RunPipeline::runPhpunit()}); this interface itself
 * carries no gating logic, only the payload — the same division of responsibility
 * `OnceProcessClassifier`'s own docblock describes for its one implementation.
 */
interface WorkerIsolation
{
    /** The FQCN for Paratest's `--runner=<class>`, or `null` to omit the flag entirely. */
    public function runnerClass(): ?string;
}
