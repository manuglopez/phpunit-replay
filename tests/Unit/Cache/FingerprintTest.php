<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\Fingerprint;
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

        $fingerprint = Fingerprint::compute($this->repo->root, 'none');

        self::assertNull($fingerprint['structural']['composer_lock']);
    }

    public function testTrackedComposerLockIsHashed(): void
    {
        $this->repo->write('composer.lock', '{"content-hash": "abc"}');
        $this->repo->commitAll('add composer.lock');

        $fingerprint = Fingerprint::compute($this->repo->root, 'none');

        self::assertIsString($fingerprint['structural']['composer_lock']);
        self::assertNotSame('', $fingerprint['structural']['composer_lock']);
    }

    public function testMissingStructuralFileIsNull(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'none');

        self::assertNull($fingerprint['structural']['phpunit_xml']);
        self::assertNull($fingerprint['structural']['phpunit_xml_dist']);
        self::assertNull($fingerprint['structural']['replay_config']);
    }

    public function testStructuralKeysArePresent(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov');

        self::assertSame(
            ['schema', 'composer_lock', 'phpunit_xml', 'phpunit_xml_dist', 'replay_config'],
            array_keys($fingerprint['structural']),
        );
        self::assertSame(Fingerprint::SCHEMA_VERSION, $fingerprint['structural']['schema']);
    }

    public function testEnvironmentalKeysReflectRuntime(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov');

        self::assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $fingerprint['environmental']['php']);
        self::assertSame('pcov', $fingerprint['environmental']['driver']);
        self::assertSame(PHP_OS_FAMILY, $fingerprint['environmental']['os']);
    }

    public function testChangingTrackedPhpunitXmlChangesStructuralAndIsReportedByDrift(): void
    {
        $this->repo->write('phpunit.xml', '<phpunit><testsuites></testsuites></phpunit>');
        $this->repo->commitAll('add phpunit.xml');

        $before = Fingerprint::compute($this->repo->root, 'none');

        $this->repo->write('phpunit.xml', '<phpunit><testsuites><testsuite name="x"/></testsuites></phpunit>');

        $after = Fingerprint::compute($this->repo->root, 'none');

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
        $before = Fingerprint::compute($this->repo->root, 'pcov');
        $after = Fingerprint::compute($this->repo->root, 'xdebug');

        self::assertSame(['driver'], Fingerprint::environmentalDrift($before, $after));
    }

    public function testEnvironmentalDriftIsEmptyWhenNothingChanges(): void
    {
        $before = Fingerprint::compute($this->repo->root, 'pcov');
        $after = Fingerprint::compute($this->repo->root, 'pcov');

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
