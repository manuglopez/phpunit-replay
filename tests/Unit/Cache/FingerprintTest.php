<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Coverage\CoverageFormat;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\TestCase;

final class FingerprintTest extends TestCase
{
    private GitRepo $repo;

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    public function testUntrackedComposerLockIsNull(): void
    {
        $this->repo->write('composer.lock', '{"content-hash": "abc"}');
        // deliberately not added/committed: untracked.

        $fingerprint = Fingerprint::compute($this->repo->root, 'none', false);

        self::assertNull($fingerprint['structural']['composer_lock']);
    }

    public function testTrackedComposerLockIsHashed(): void
    {
        $this->repo->write('composer.lock', '{"content-hash": "abc"}');
        $this->repo->commitAll('add composer.lock');

        $fingerprint = Fingerprint::compute($this->repo->root, 'none', false);

        self::assertIsString($fingerprint['structural']['composer_lock']);
        self::assertNotSame('', $fingerprint['structural']['composer_lock']);
    }

    public function testMissingStructuralFileIsNull(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'none', false);

        self::assertNull($fingerprint['structural']['phpunit_xml']);
        self::assertNull($fingerprint['structural']['phpunit_xml_dist']);
        self::assertNull($fingerprint['structural']['replay_config']);
    }

    public function testStructuralKeysArePresent(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertSame(
            ['schema', 'edges_exclude_ignored', 'edges_by_running_class', 'composer_lock', 'phpunit_xml', 'phpunit_xml_dist', 'replay_config'],
            array_keys($fingerprint['structural']),
        );
        self::assertSame(Fingerprint::SCHEMA_VERSION, $fingerprint['structural']['schema']);
    }

    /**
     * Unlike `static_declaration_edges` (present only while that flag is on),
     * `edges_exclude_ignored` is unconditional: it is not an opt-in, it describes this
     * package's own (now fixed) edge-recording behaviour, so it must be `true` regardless
     * of the flag or the driver.
     */
    public function testEdgesExcludeIgnoredIsAlwaysPresentAndTrue(): void
    {
        self::assertTrue(Fingerprint::compute($this->repo->root, 'pcov', false)['structural']['edges_exclude_ignored']);
        self::assertTrue(Fingerprint::compute($this->repo->root, 'xdebug', true)['structural']['edges_exclude_ignored']);
        self::assertTrue(Fingerprint::compute($this->repo->root, 'none', false)['structural']['edges_exclude_ignored']);
    }

    /**
     * The whole reason this is a NAMED key rather than a bare SCHEMA_VERSION bump:
     * structuralDrift() always skips 'schema' (its $skipKey), so a schema-only bump can
     * never be named in a drift report. A graph recorded before this key existed has no
     * 'edges_exclude_ignored' entry at all — exactly what an on-disk graph.json recorded by
     * an older release of this package looks like the moment this version runs — and that
     * absence must be reported, by name, as structural drift, forcing the one-time fresh
     * record this behaviour change requires.
     */
    public function testAnOldFingerprintMissingTheKeyIsNamedStructuralDrift(): void
    {
        $stored = Fingerprint::compute($this->repo->root, 'pcov', false);
        unset($stored['structural']['edges_exclude_ignored']);

        $current = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertSame(['edges_exclude_ignored'], Fingerprint::structuralDrift($stored, $current));
        self::assertFalse(Fingerprint::structuralMatches($stored, $current));
    }

    /**
     * `edges_by_running_class` (Fingerprint's own class docblock): unconditional, exactly
     * like `edges_exclude_ignored` above and for the same kind of reason — it describes this
     * package's own edge-attribution behaviour (a test method inherited from an abstract base
     * now credits the concrete, running class's file), not a project opt-in.
     */
    public function testEdgesByRunningClassIsAlwaysPresentAndTrue(): void
    {
        self::assertTrue(Fingerprint::compute($this->repo->root, 'pcov', false)['structural']['edges_by_running_class']);
        self::assertTrue(Fingerprint::compute($this->repo->root, 'xdebug', true)['structural']['edges_by_running_class']);
        self::assertTrue(Fingerprint::compute($this->repo->root, 'none', false)['structural']['edges_by_running_class']);
    }

    /**
     * Same one-time invalidation `edges_exclude_ignored` forces, named separately: a graph
     * recorded before this key existed has abstract-base-declared test methods' edges under
     * the wrong file entirely, and `structuralDrift()` must name it so a fresh record is not
     * an unexplained full discard.
     */
    public function testAnOldFingerprintMissingTheEdgesByRunningClassKeyIsNamedStructuralDrift(): void
    {
        $stored = Fingerprint::compute($this->repo->root, 'pcov', false);
        unset($stored['structural']['edges_by_running_class']);

        $current = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertSame(['edges_by_running_class'], Fingerprint::structuralDrift($stored, $current));
        self::assertFalse(Fingerprint::structuralMatches($stored, $current));
    }

    public function testEnvironmentalKeysReflectRuntime(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertSame(['php', 'driver', 'os', 'coverage'], array_keys($fingerprint['environmental']));
        self::assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $fingerprint['environmental']['php']);
        self::assertSame('pcov', $fingerprint['environmental']['driver']);
        self::assertSame(PHP_OS_FAMILY, $fingerprint['environmental']['os']);
        self::assertSame(CoverageFormat::id(), $fingerprint['environmental']['coverage']);
    }

    /**
     * A cached result is only replayable while the coverage snapshot recorded next to it is
     * still readable, and php-code-coverage changes both the `--coverage-php` serialization
     * format and the shape of the coverage data between majors. Nothing used to notice: the
     * `environmental` bucket recorded php/driver/os only, so a graph recorded under one
     * php-code-coverage was replayed under another and its snapshots came back empty or
     * unreadable — silently missing coverage rather than a re-run.
     */
    public function testEnvironmentalDriftReportsACoverageFormatChange(): void
    {
        $stored = Fingerprint::compute($this->repo->root, 'pcov', false);
        $current = $stored;

        $stored['environmental']['coverage'] = 'cc12/legacy/snap1';

        self::assertSame(['coverage'], Fingerprint::environmentalDrift($stored, $current));
        self::assertSame(['coverage'], Fingerprint::environmentalDrift($current, $stored));
    }

    /**
     * The caches that already exist on disk: recorded by a release whose `environmental`
     * bucket had no `coverage` key at all, and whose `.cov` snapshots were serialized
     * php-code-coverage objects. Drift has to fire for those too, or the very cache this key
     * was added for is the one it misses.
     */
    public function testEnvironmentalDriftReportsAFingerprintRecordedWithoutACoverageKey(): void
    {
        $current = Fingerprint::compute($this->repo->root, 'pcov', false);
        $stored = $current;

        unset($stored['environmental']['coverage']);

        self::assertSame(['coverage'], Fingerprint::environmentalDrift($stored, $current));
    }

    /**
     * The coverage format lives in the `environmental` bucket, not the `structural` one, on
     * purpose: it must clear the cached RESULTS (whose snapshots are unreadable) without
     * invalidating the dependency EDGES (which are this package's own line maps and are not
     * affected), and `canonicalStructural()` feeds the structural bucket into every content
     * key. Which is also why `SCHEMA_VERSION` was not bumped for it.
     *
     * Part of the environmental bucket now feeds the content key as well
     * (`canonicalResultEnvironment()`), so "not part of the content key input" takes two
     * assertions rather than one: neither canonical string may name it.
     */
    public function testTheCoverageFormatIsNotPartOfTheContentKeyInput(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertArrayNotHasKey('coverage', $fingerprint['structural']);
        self::assertStringNotContainsString('coverage', Fingerprint::canonicalStructural($fingerprint));
        self::assertStringNotContainsString('coverage', Fingerprint::canonicalResultEnvironment($fingerprint));
    }

    /**
     * `canonicalResultEnvironment()` answers a narrower question than the bucket it reads:
     * which part of this machine can change a test's OUTCOME. `php` and `os` can, so they are
     * in every content key; the driver decides which lines are reported rather than whether an
     * assertion passed, and the coverage format guards a local snapshot store the address
     * knows nothing about. Both of those stay in the bucket only, where they clear results
     * without touching edges.
     */
    public function testCanonicalResultEnvironmentCoversPhpAndOsOnly(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertSame(
            '{"os":"' . PHP_OS_FAMILY . '","php":"' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '"}',
            Fingerprint::canonicalResultEnvironment($fingerprint),
        );
    }

    public function testTheCoverageDriverIsNotPartOfTheContentKeyInput(): void
    {
        $pcov = Fingerprint::compute($this->repo->root, 'pcov', false);
        $xdebug = Fingerprint::compute($this->repo->root, 'xdebug', false);

        self::assertSame(['driver'], Fingerprint::environmentalDrift($pcov, $xdebug));
        self::assertSame(
            Fingerprint::canonicalResultEnvironment($pcov),
            Fingerprint::canonicalResultEnvironment($xdebug),
        );
    }

    /**
     * `php` and `os` are in the address AND in the bucket, and that redundancy is
     * load-bearing: a graph adopted from a machine they differ on still `structuralMatches()`,
     * so its edges are inherited while its results carry addresses this machine will never
     * compute. Environmental drift is the only thing that sweeps those out.
     */
    public function testPhpAndOsStillDriftEnvironmentally(): void
    {
        $stored = Fingerprint::compute($this->repo->root, 'pcov', false);
        $current = $stored;

        $stored['environmental']['php'] = '1.0';
        $stored['environmental']['os'] = 'Haiku';

        self::assertSame(['php', 'os'], Fingerprint::environmentalDrift($stored, $current));
    }

    public function testCanonicalResultEnvironmentIsDeterministicRegardlessOfKeyOrder(): void
    {
        $a = ['environmental' => ['php' => '8.4', 'driver' => 'pcov', 'os' => 'Linux', 'coverage' => 'x']];
        $b = ['environmental' => ['coverage' => 'x', 'os' => 'Linux', 'driver' => 'pcov', 'php' => '8.4']];

        self::assertSame(
            Fingerprint::canonicalResultEnvironment($a),
            Fingerprint::canonicalResultEnvironment($b),
        );
        self::assertSame('{"os":"Linux","php":"8.4"}', Fingerprint::canonicalResultEnvironment($a));
    }

    /**
     * Every graph.json written before this key existed, plus the malformed ones
     * `Graph::decode()` tolerates. `bucket()` already answers "no bucket" with an empty array,
     * and this must not stack a second failure mode on top of it: a key that is missing stays
     * missing from the canonical string rather than being invented as null.
     */
    public function testCanonicalResultEnvironmentToleratesAMissingBucketOrKey(): void
    {
        self::assertSame('[]', Fingerprint::canonicalResultEnvironment([]));
        self::assertSame('[]', Fingerprint::canonicalResultEnvironment(['environmental' => 'not an array']));
        self::assertSame('[]', Fingerprint::canonicalResultEnvironment(['environmental' => ['driver' => 'pcov']]));
        self::assertSame(
            '{"php":"8.2"}',
            Fingerprint::canonicalResultEnvironment(['environmental' => ['php' => '8.2']]),
        );
    }

    public function testChangingTrackedPhpunitXmlChangesStructuralAndIsReportedByDrift(): void
    {
        $this->repo->write('phpunit.xml', '<phpunit><testsuites></testsuites></phpunit>');
        $this->repo->commitAll('add phpunit.xml');

        $before = Fingerprint::compute($this->repo->root, 'none', false);

        $this->repo->write('phpunit.xml', '<phpunit><testsuites><testsuite name="x"/></testsuites></phpunit>');

        $after = Fingerprint::compute($this->repo->root, 'none', false);

        self::assertNotSame($before['structural']['phpunit_xml'], $after['structural']['phpunit_xml']);
        self::assertFalse(Fingerprint::structuralMatches($before, $after));
        self::assertSame(['phpunit_xml'], Fingerprint::structuralDrift($before, $after));
    }

    public function testStructuralDriftIgnoresSchemaKey(): void
    {
        $before = ['structural' => ['schema' => 1, 'composer_lock' => 'aaa']];
        $after = ['structural' => ['schema' => 2, 'composer_lock' => 'aaa']];

        self::assertSame([], Fingerprint::structuralDrift($before, $after));
    }

    public function testEnvironmentalDriftReportsDriverChange(): void
    {
        $before = Fingerprint::compute($this->repo->root, 'pcov', false);
        $after = Fingerprint::compute($this->repo->root, 'xdebug', false);

        self::assertSame(['driver'], Fingerprint::environmentalDrift($before, $after));
    }

    public function testEnvironmentalDriftIsEmptyWhenNothingChanges(): void
    {
        $before = Fingerprint::compute($this->repo->root, 'pcov', false);
        $after = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertSame([], Fingerprint::environmentalDrift($before, $after));
    }

    public function testStructuralMatchesIgnoresKeyOrder(): void
    {
        $a = ['structural' => ['schema' => 1, 'composer_lock' => 'aaa', 'phpunit_xml' => null]];
        $b = ['structural' => ['phpunit_xml' => null, 'schema' => 1, 'composer_lock' => 'aaa']];

        self::assertTrue(Fingerprint::structuralMatches($a, $b));
    }

    public function testCanonicalStructuralIsDeterministicRegardlessOfKeyOrder(): void
    {
        $a = ['structural' => ['schema' => 1, 'composer_lock' => 'aaa', 'phpunit_xml' => null]];
        $b = ['structural' => ['phpunit_xml' => null, 'schema' => 1, 'composer_lock' => 'aaa']];

        self::assertSame(Fingerprint::canonicalStructural($a), Fingerprint::canonicalStructural($b));
        self::assertSame(
            '{"composer_lock":"aaa","phpunit_xml":null,"schema":1}',
            Fingerprint::canonicalStructural($a),
        );
    }
}
