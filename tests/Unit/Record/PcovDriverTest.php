<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\PcovDriver;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Tests\Unit\Record\Fixtures\CoverageSubject;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real pcov extension. Requires `-d pcov.enabled=1`. Verified empirically
 * (pcov 1.0.12): when the `pcov.directory` ini setting is left empty, pcov tracks NO
 * files at all — `ini_get('pcov.directory')` reports '' and `\pcov\waiting()` stays
 * empty regardless of cwd, even though `phpinfo()` cosmetically displays the cwd as a
 * resolved default. `pcov.directory` must be explicitly set (e.g. `-d pcov.directory=.`)
 * for anything to be tracked, and it must cover this file's directory (not just src/)
 * for the fixture under tests/ to be tracked — SPEC §2.4 has the wrapper launch PHP with
 * `-d pcov.directory=<root>` for exactly this reason. This test skips instead of failing
 * when the ambient `pcov.directory` will not track the fixture.
 */
#[RequiresPhpExtension('pcov')]
final class PcovDriverTest extends TestCase
{
    #[Test]
    public function it_collects_hits_for_the_fixture_file_and_never_for_vendor_files(): void
    {
        if (! PcovDriver::available()) {
            self::markTestSkipped('pcov.enabled=0; rerun with -d pcov.enabled=1.');
        }

        $projectRoot = dirname(__DIR__, 3);

        $fixtureFile = realpath(__DIR__ . '/Fixtures/CoverageSubject.php');
        self::assertNotFalse($fixtureFile, 'fixture file must exist');

        $configuredDirectory = (string) ini_get('pcov.directory');

        if ($configuredDirectory === '') {
            self::markTestSkipped(
                sprintf(
                    'pcov.directory is unset, so pcov tracks nothing; rerun with -d pcov.directory=%s (SPEC §2.4).',
                    $projectRoot,
                ),
            );
        }

        $pcovDirectory = realpath($configuredDirectory);

        if ($pcovDirectory === false || ! str_starts_with($fixtureFile, $pcovDirectory . DIRECTORY_SEPARATOR)) {
            self::markTestSkipped(
                sprintf(
                    'pcov.directory (%s) does not cover the fixture file; rerun with -d pcov.directory=%s (SPEC §2.4).',
                    $configuredDirectory,
                    $projectRoot,
                ),
            );
        }

        $scope = SourceScope::fromProjectRoot($projectRoot);
        $driver = new PcovDriver($scope);

        $driver->start();
        $subject = new CoverageSubject();
        $subject->add(1, 2);
        $data = $driver->stop();

        self::assertArrayHasKey($fixtureFile, $data);

        $hasHit = false;

        foreach ($data[$fixtureFile] as $count) {
            if ($count > 0) {
                $hasHit = true;

                break;
            }
        }

        self::assertTrue($hasHit, 'expected at least one executed line in the fixture file');

        foreach (array_keys($data) as $file) {
            self::assertStringNotContainsString(DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR, $file);
        }
    }
}
