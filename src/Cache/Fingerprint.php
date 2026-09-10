<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Analysis\DeclarationScanner;
use Manuglopez\Replay\Coverage\CoverageFormat;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/Fingerprint.php
 *
 * Ported structural file list per SPEC §4.5 (Pest's vite/js/package-lock handling is dropped
 * entirely — this package does not track frontend build tooling).
 *
 * Trackedness check: a structural file is only hashed when it is tracked by git. This is
 * checked with `git ls-files --error-unmatch <file>` (symfony/process, 5s timeout) rather
 * than Symfony Finder's `ignoreVCSIgnored()` (Pest's original approach): Finder only excludes
 * files matched by `.gitignore`, so a brand-new file that was never `git add`ed but also isn't
 * gitignored would still be treated as "tracked" — the opposite of what SPEC §4.5 requires.
 * When the project root is not a git repository at all (no `.git` directory or file), every
 * file is treated as tracked, since there is no index to distrust.
 *
 * ## The two buckets
 *
 * `structural` describes the PROJECT and feeds the content key (`Cache\ContentKey`): a change
 * to any of it makes the whole graph unusable. `environmental` describes the MACHINE and only
 * invalidates the cached RESULTS (`RunPipeline::reconcile()` / `ReplayState`): a change there
 * leaves the dependency edges standing but throws away the recorded outcomes, because those
 * were observed under conditions that no longer hold.
 *
 * `environmental` is not entirely out of the address, though. The part of it that can change
 * a test's OUTCOME — `php` and `os` — feeds the content key as well, through
 * `canonicalResultEnvironment()`, whose docblock argues which keys qualify and why `driver`
 * and `coverage` do not.
 *
 * ## `php` and `os` sit in both halves
 *
 * The keys `canonicalResultEnvironment()` names stay in the `environmental` bucket too, and
 * that redundancy is load-bearing rather than a duplicate waiting to be tidied away. A graph
 * adopted from another machine still `structuralMatches()`, so its EDGES are inherited —
 * correctly, since an edge is a property of the tree and not of the machine that observed it
 * — while its RESULTS carry content keys this machine will now never compute.
 * `environmentalDrift()` → `Graph::clearResults()` is the only thing that sweeps that dead
 * weight out of `baselines[<branch>]['results']`; take `php`/`os` out of the bucket and drift
 * stops firing, so the adopted results stay there forever, unaddressable and unreachable.
 *
 * `SCHEMA_VERSION` is deliberately NOT the knob for an environmental change: it sits in the
 * structural bucket, `canonicalStructural()` feeds it into every content key, and bumping it
 * therefore discards every graph on every machine. It describes the shape of this array, and
 * only moves when that shape does.
 *
 * It was not bumped when `canonicalResultEnvironment()` was folded into the content key
 * either, and neither was a named structural key added for it. Both exist to force a fresh
 * record when a graph's edges have become untrustworthy, and this change does not touch what
 * an edge means: it changes every address at once, so nothing recorded under the old material
 * can be found, while a graph whose structural bucket is genuinely unchanged rightly keeps
 * its edges and the results it recorded here itself. The one thing a stale stored key costs
 * is a single skipped flip check per test (`GraphUpdater::detectFlip()` needs the old and
 * new keys to match), which is what any address change costs.
 *
 * ## `static_declaration_edges`
 *
 * The `static_declaration_edges` config flag changes what an edge *means*
 * (`Analysis\StaticEdges`), so a graph recorded with it on and a graph recorded with it off
 * must never be mixed: the edges are not comparable, and neither are the keys computed from
 * them. That makes it `structural`, not `environmental` — `environmental` only throws away
 * the recorded *results* and keeps the edges standing, which is precisely the wrong half.
 *
 * It is added to the bucket ONLY when the flag is on. Adding it unconditionally, even as
 * `false`, would change `canonicalStructural()` for every project on earth and invalidate
 * every existing cache on every machine the moment this version shipped — the same blast
 * radius as bumping `SCHEMA_VERSION`, for a feature nobody asked for yet. Absent-vs-present
 * still drifts in both directions (`detectDrift()` walks both sides), so flipping the flag
 * discards the graph deliberately, in exactly one direction at a time.
 *
 * `analysis_rules` rides along with it, for the same reason and with the same scope. It is
 * `Analysis\DeclarationScanner::RULES_VERSION`, and the argument for putting the flag here —
 * it "changes what an edge means" — applies verbatim to a change in the classification rules.
 * Bumping the rules version re-parses `<stateDir>/analysis/` but changes no *content* key, so
 * without this it re-ran no test and therefore never corrected an edge the old rules got
 * wrong: `Cache\Graph::unionEdges()` only ever grows an edge set, so a dropped edge stays
 * dropped and an invented one stays invented, on every machine, forever. A rules bump is not
 * a cache detail, it is a change of meaning, and it costs a fresh record — which is why it is
 * a separate key rather than folded into the flag's value: `structuralDrift()` then names
 * `analysis_rules` when only the rules moved, and a user who changed nothing gets told why.
 *
 * ## `edges_by_running_class`
 *
 * Present UNCONDITIONALLY, same as `edges_exclude_ignored` below and for the same kind of
 * reason: a test method inherited from an abstract base used to have its edges, its result,
 * and any class-level `#[NotCacheable]` marker all attributed to the file that DECLARES the
 * method (`PHPUnit\Event\Code\TestMethod::file()`, which reflects the declaring class) rather
 * than the file of the concrete class actually running it (`PHPUnit\Subscribers\*`, now
 * `PHPUnit\TestMethodFile::of()`, reflecting `TestMethod::className()` instead). A graph
 * recorded before this fix has every one of those inherited tests' edges sitting under the
 * abstract base's file — which is never itself a file this package selects or re-runs — and
 * has nothing at all under the concrete subclass that actually needs to be. `Cache\ContentKey`
 * is computed from a test file's dependency list, so the concrete file's content key was
 * computed from an empty (or altogether absent) dependency set: wrong in the specific
 * direction that makes it look permanently uncached rather than merely stale, since an
 * ordinary structural drift at least leaves a REAL, if outdated, key to invalidate.
 *
 * This key changes what an edge means in exactly the sense `static_declaration_edges` and
 * `edges_exclude_ignored` already document: the SET of files an edge can be recorded under is
 * different before and after, so a content key computed under the old attribution and one
 * computed under the new one are never comparable, which is what makes this structural rather
 * than environmental. It has no opt-in to gate on, unlike `static_declaration_edges` — every
 * project either has this class of test shape or does not, there is no configuration
 * describing it — so, like `edges_exclude_ignored`, it is simplest and safest to always be
 * present rather than present-only-when-relevant: a project with no abstract-base test
 * classes at all is unaffected either way (the concrete-class file and the declaring file are
 * the same file, so nothing about its recorded edges actually changes), and pays only the one
 * one-time fresh record every graph recorded before this key existed forces via
 * `structuralDrift()` (which, same as `edges_exclude_ignored`, can name it — `status` reports
 * `edges_by_running_class (drift)` — rather than the user seeing an unexplained full discard,
 * the bare `SCHEMA_VERSION` bump `structuralDrift()`'s `detectDrift()` always skips would be).
 *
 * ## `edges_exclude_ignored`
 *
 * Unlike `static_declaration_edges` above, this key is present UNCONDITIONALLY — always
 * `true`, never absent — because it does not describe an opt-in a project can leave alone; it
 * describes this package's own edge-recording behaviour (`Cache\GraphUpdater::apply()` no
 * longer records an edge to a file `git check-ignore` matches — a compiled Laravel Blade view
 * under `bootstrap/cache/`, most commonly). Every graph recorded before this key existed is
 * missing it, so `structuralDrift()` reports it for every such graph the first time this
 * version runs, forcing exactly the one fresh record that behaviour change requires. A bare
 * `SCHEMA_VERSION` bump would force the same fresh record but could never be *named* in the
 * drift report — `structuralDrift()` always skips `'schema'` — so the user would see the
 * whole graph discarded with nothing to explain why. It rides in the same structural bucket
 * `static_declaration_edges` does, for the same reason: this changes what an edge means, and
 * a content key computed from an old edge set and one computed from a new one are not
 * comparable.
 */
final readonly class Fingerprint
{
    /** @var int */
    public const SCHEMA_VERSION = 1;

    /** @var array<string, string> structural key => project-relative file */
    private const STRUCTURAL_FILES = [
        'composer_lock' => 'composer.lock',
        'phpunit_xml' => 'phpunit.xml',
        'phpunit_xml_dist' => 'phpunit.xml.dist',
        'replay_config' => 'phpunit-replay.php',
    ];

    /** @var list<string> environmental keys that feed the content key ({@see self::canonicalResultEnvironment()}) */
    private const RESULT_ENVIRONMENT_KEYS = ['php', 'os'];

    /**
     * `$staticDeclarationEdges` has no default on purpose. It used to default to `false`, and
     * inside one diff that omission silently disabled the structural key twice — in
     * `PullCommand`, where the remote baseline was then rejected on every pull, and in
     * `StatusCommand`, where the fingerprint read as drifted against itself. A caller that
     * has not decided is a caller that must be made to.
     *
     * @return array{structural: array<string, bool|int|string|null>, environmental: array<string, string|null>}
     */
    public static function compute(string $projectRoot, string $driver, bool $staticDeclarationEdges): array
    {
        // Named, unconditional (Fingerprint's own class docblock, "$staticDeclarationEdges"
        // section, explains why the flag above is added only when it is on — this key is
        // the deliberate opposite: it changes what an edge means for every project, on
        // every machine, the moment this ships, so it is always present rather than gated
        // on an opt-in. A bare SCHEMA_VERSION bump would invalidate the same graphs but
        // could never be NAMED in a drift report: structuralDrift()'s detectDrift() always
        // skips the 'schema' key, so the user would see every graph discarded with nothing
        // saying why. This key gets the same one-time invalidation and `status` can name
        // it (`edges_exclude_ignored (drift)`).
        //
        // `edges_by_running_class` (see class docblock) rides the same unconditional
        // treatment, for the same reason: an inherited test method's edges move from the
        // declaring (abstract base) file to the running (concrete) class's file, which is
        // a change of meaning every graph recorded before this key existed needs one fresh
        // record to correct.
        $structural = [
            'schema' => self::SCHEMA_VERSION,
            'edges_exclude_ignored' => true,
            'edges_by_running_class' => true,
        ];

        foreach (self::STRUCTURAL_FILES as $key => $relative) {
            $structural[$key] = self::trackedHash($projectRoot, $relative);
        }

        if ($staticDeclarationEdges) {
            $structural['static_declaration_edges'] = true;
            $structural['analysis_rules'] = DeclarationScanner::RULES_VERSION;
        }

        return [
            'structural' => $structural,
            'environmental' => [
                // `php` and `os` are ALSO in every content key
                // ({@see self::canonicalResultEnvironment()}) and are kept here as well on
                // purpose: this bucket is what discards the results of a graph adopted from
                // a machine those two keys differ on, whose addresses this machine can no
                // longer compute (the class docblock's "`php` and `os` sit in both halves").
                'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'driver' => $driver,
                'os' => PHP_OS_FAMILY,
                // A recorded result is only replayable while its stored coverage snapshot is
                // still readable, and php-code-coverage changes both the `--coverage-php`
                // serialization format and the shape of the coverage data itself between
                // majors ({@see CoverageFormat}). Without this key nothing noticed: a cache
                // recorded under one format was read back under another as an empty or
                // unreadable snapshot, i.e. as silently missing coverage.
                'coverage' => CoverageFormat::id(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function structuralMatches(array $a, array $b): bool
    {
        $aStructural = self::bucket($a, 'structural');
        $bStructural = self::bucket($b, 'structural');

        ksort($aStructural);
        ksort($bStructural);

        return $aStructural === $bStructural;
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $current
     * @return list<string>
     */
    public static function structuralDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::bucket($stored, 'structural'),
            self::bucket($current, 'structural'),
            'schema',
        );
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $current
     * @return list<string>
     */
    public static function environmentalDrift(array $stored, array $current): array
    {
        return self::detectDrift(
            self::bucket($stored, 'environmental'),
            self::bucket($current, 'environmental'),
        );
    }

    /**
     * Canonical JSON of the structural bucket (ksort, JSON_UNESCAPED_SLASHES) — input to the content key.
     *
     * @param array<string, mixed> $fingerprint
     */
    public static function canonicalStructural(array $fingerprint): string
    {
        $structural = self::bucket($fingerprint, 'structural');
        ksort($structural);

        return json_encode($structural, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Canonical JSON of the outcome-relevant subset of the environmental bucket (ksort,
     * JSON_UNESCAPED_SLASHES) — the second input to the content key, beside
     * `canonicalStructural()`.
     *
     * The question this answers is deliberately narrower than "is this the same machine", and
     * the method's name is what keeps the two apart: it is *which part of this machine can
     * change a test's outcome*. Only `php` (`MAJOR.MINOR`) and `os` (`PHP_OS_FAMILY`) can — a
     * result may legitimately differ across a PHP minor, which is a thing suites exist to
     * catch, and across an OS by way of path separators, locale and filesystem behaviour — so
     * those two, and nothing else, are in the address. A key missing from the stored bucket
     * stays missing from the JSON, exactly as in `canonicalStructural()`; an old graph
     * recorded before a key existed is addressed by what it does have.
     *
     * `driver` is deliberately OUT. Which coverage driver is loaded decides which lines get
     * *reported*, not whether an assertion passed. This package's own composer scripts
     * alternate the two (`test` runs under pcov, `test:xdebug` under xdebug) and a project
     * doing the same would halve its hit rate daily in exchange for no correctness at all.
     * The residual risk is timing and error handling, which xdebug does change: a
     * timing-sensitive test could in principle flip. That is traded away knowingly.
     *
     * `coverage` ({@see CoverageFormat}) is deliberately OUT for a different reason: it
     * protects the LOCAL snapshot store and nothing else. Snapshots are written under
     * `<stateDir>/coverage/` at record time and are never published, so a result adopted from
     * a remote carries no snapshot for a format mismatch to corrupt — which is why
     * `Report\CoverageMerger` counts a replayed file with no readable snapshot rather than
     * trusting one. Discarding the results whose snapshots became unreadable is the whole of
     * that key's job, and this bucket already does it.
     *
     * Why the address rather than one more check: environmental drift only ever discards the
     * results a machine recorded itself (`RunPipeline::reconcile()` → `Graph::clearResults()`).
     * Nothing re-checks the environment of a result ADOPTED from a remote cache —
     * `RunPipeline::replayFromRemote()` weighs the key, the object's existence and whether it
     * holds a status that forces a re-run, and that is all — so a result recorded under one
     * PHP minor was findable, and replayable, by a machine on another. Inside the address that
     * read is unreachable instead of merely unchecked, which is also what makes an object
     * recorded elsewhere provably collectable rather than just absent from today's graph.
     *
     * @param array<string, mixed> $fingerprint
     */
    public static function canonicalResultEnvironment(array $fingerprint): string
    {
        $environmental = self::bucket($fingerprint, 'environmental');

        $subset = [];

        foreach (self::RESULT_ENVIRONMENT_KEYS as $key) {
            if (array_key_exists($key, $environmental)) {
                $subset[$key] = $environmental[$key];
            }
        }

        ksort($subset);

        return json_encode($subset, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return list<string>
     */
    private static function detectDrift(array $a, array $b, ?string $skipKey = null): array
    {
        $drifts = [];

        foreach ($a as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }

            if (($b[$key] ?? null) !== $value) {
                $drifts[] = $key;
            }
        }

        foreach ($b as $key => $value) {
            if ($key === $skipKey) {
                continue;
            }

            if (! array_key_exists($key, $a) && $value !== null) {
                $drifts[] = $key;
            }
        }

        return array_values(array_unique($drifts));
    }

    /**
     * @param array<string, mixed> $fingerprint
     * @return array<string, mixed>
     */
    private static function bucket(array $fingerprint, string $key): array
    {
        $raw = $fingerprint[$key] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $normalised = [];

        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $normalised[$k] = $v;
            }
        }

        return $normalised;
    }

    private static function trackedHash(string $projectRoot, string $relative): ?string
    {
        $absolute = $projectRoot . '/' . $relative;

        if (! is_file($absolute) || ! self::isTrackedByGit($projectRoot, $relative)) {
            return null;
        }

        return ContentHash::of($absolute);
    }

    private static function isTrackedByGit(string $projectRoot, string $relative): bool
    {
        if (! is_dir($projectRoot . '/.git') && ! is_file($projectRoot . '/.git')) {
            return true;
        }

        $process = new Process(['git', 'ls-files', '--error-unmatch', $relative], $projectRoot);
        $process->setTimeout(5.0);

        try {
            $process->run();
        } catch (ExceptionInterface) {
            return false;
        }

        return $process->isSuccessful();
    }
}
