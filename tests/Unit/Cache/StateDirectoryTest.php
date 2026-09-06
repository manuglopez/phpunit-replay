<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\ProjectKey;
use Manuglopez\Replay\Cache\StateDirectory;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class StateDirectoryTest extends TestCase
{
    public function testResolveReturnsAbsoluteConfiguredPathUnchanged(): void
    {
        self::assertSame('/mnt/cache/replay', StateDirectory::resolve('/mnt/cache/replay', '/project'));
    }

    public function testResolveJoinsRelativeConfiguredPathToRoot(): void
    {
        self::assertSame('/project/.state/replay', StateDirectory::resolve('.state/replay', '/project'));
        self::assertSame('/project/state', StateDirectory::resolve('state', '/project'));
    }

    public function testResolveFallsBackToHomeDirectoryWithProjectKeyWhenNull(): void
    {
        $home = TempDir::make('home');
        $previousHome = getenv('HOME');

        try {
            putenv('HOME=' . $home);

            $projectRoot = TempDir::make('project');

            try {
                $expected = $home . '/.phpunit-replay/' . ProjectKey::for($projectRoot);

                self::assertSame($expected, StateDirectory::resolve(null, $projectRoot));
            } finally {
                TempDir::remove($projectRoot);
            }
        } finally {
            $previousHome === false ? putenv('HOME') : putenv('HOME=' . $previousHome);
            TempDir::remove($home);
        }
    }

    public function testResolveFallsBackToProjectRootWhenNoHomeDirectory(): void
    {
        $previousHome = getenv('HOME');
        $previousProfile = getenv('USERPROFILE');

        try {
            putenv('HOME=/definitely/does/not/exist/replay-tests');
            putenv('USERPROFILE=/definitely/does/not/exist/replay-tests');

            $projectRoot = TempDir::make('project-no-home');

            try {
                self::assertSame($projectRoot . '/.phpunit-replay', StateDirectory::resolve(null, $projectRoot));
            } finally {
                TempDir::remove($projectRoot);
            }
        } finally {
            $previousHome === false ? putenv('HOME') : putenv('HOME=' . $previousHome);
            $previousProfile === false ? putenv('USERPROFILE') : putenv('USERPROFILE=' . $previousProfile);
        }
    }
}
