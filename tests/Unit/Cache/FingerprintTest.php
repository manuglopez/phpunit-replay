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
            ['schema', 'composer_lock', 'phpunit_xml', 'phpunit_xml_dist', 'replay_config'],
            array_keys($fingerprint['structural']),
        );
        self::assertSame(Fingerprint::SCHEMA_VERSION, $fingerprint['structural']['schema']);
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
     */
    public function testTheCoverageFormatIsNotPartOfTheContentKeyInput(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertArrayNotHasKey('coverage', $fingerprint['structural']);
        self::assertStringNotContainsString('coverage', Fingerprint::canonicalStructural($fingerprint));
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
