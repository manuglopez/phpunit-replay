<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\Extension\ParameterCollection;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::defaults();

        self::assertNull($config->stateDir);
        self::assertNull($config->remote);
        self::assertNull($config->remoteToken);
        self::assertNull($config->defaultBranch);
        self::assertSame([], $config->watch);
        self::assertSame([], $config->neverCache);
        self::assertSame(20, $config->quarantineReleaseAfter);
        self::assertSame('auto', $config->laravel);
        self::assertTrue($config->junitMerge);
        self::assertSame('auto', $config->mode);
        self::assertFalse($config->hermeticityHeuristics);
        self::assertSame('objects', $config->remotePush);
        self::assertSame('main', $config->remoteBranch);
        self::assertSame(300, $config->remoteRefreshSeconds);
        self::assertSame(60, $config->remoteTimeout);
        self::assertSame([], $config->baselineBranches);
    }

    public function testFromArrayReadsValidValues(): void
    {
        $config = Config::fromArray([
            'state_dir' => '/mnt/cache',
            'remote' => 'https://cache.example/replay/',
            'remote_token' => 'secret',
            'default_branch' => 'main',
            'watch' => ['config/billing/**' => 'tests/Feature/Billing', 'config/tax/**' => ['tests/Feature/Tax', 'tests/Unit/Tax']],
            'never_cache' => ['tests/Feature/FlakyTest.php'],
            'quarantine_release_after' => 5,
            'laravel' => 'on',
            'junit_merge' => false,
            'mode' => 'record',
            'hermeticity_heuristics' => true,
            'remote_push' => 'all',
            'remote_branch' => 'cache',
            'remote_refresh_seconds' => 30,
            'remote_timeout' => 120,
            'baseline_branches' => ['develop', 'main'],
        ]);

        self::assertSame('/mnt/cache', $config->stateDir);
        self::assertSame('https://cache.example/replay/', $config->remote);
        self::assertSame('secret', $config->remoteToken);
        self::assertSame('main', $config->defaultBranch);
        self::assertSame(
            ['config/billing/**' => 'tests/Feature/Billing', 'config/tax/**' => ['tests/Feature/Tax', 'tests/Unit/Tax']],
            $config->watch,
        );
        self::assertSame(['tests/Feature/FlakyTest.php'], $config->neverCache);
        self::assertSame(5, $config->quarantineReleaseAfter);
        self::assertSame('on', $config->laravel);
        self::assertFalse($config->junitMerge);
        self::assertSame('record', $config->mode);
        self::assertTrue($config->hermeticityHeuristics);
        self::assertSame('all', $config->remotePush);
        self::assertSame('cache', $config->remoteBranch);
        self::assertSame(30, $config->remoteRefreshSeconds);
        self::assertSame(120, $config->remoteTimeout);
        self::assertSame(['develop', 'main'], $config->baselineBranches);
    }

    public function testFromArrayIgnoresUnknownKeys(): void
    {
        $config = Config::fromArray(['this_key_does_not_exist' => 'whatever']);

        self::assertEquals(Config::defaults(), $config);
    }

    public function testFromArrayFallsBackToDefaultsForWrongTypes(): void
    {
        $config = Config::fromArray([
            'state_dir' => 123,
            'remote' => false,
            'remote_token' => ['nope'],
            'default_branch' => 3.14,
            'watch' => 'not-an-array',
            'never_cache' => 'not-an-array',
            'quarantine_release_after' => 'twenty',
            'laravel' => 'invalid-value',
            'junit_merge' => 'yes',
            'mode' => 'nonsense',
            'hermeticity_heuristics' => 'true',
            'remote_push' => 'everything',
            'remote_branch' => '',
            'remote_refresh_seconds' => '30',
            'remote_timeout' => 12.5,
            'baseline_branches' => 'develop',
        ]);

        self::assertEquals(Config::defaults(), $config);
    }

    public function testFromArrayDropsInvalidWatchAndNeverCacheEntriesButKeepsValidOnes(): void
    {
        $config = Config::fromArray([
            'watch' => [
                'valid/string' => 'tests/Feature',
                'valid/list' => ['tests/A', 'tests/B'],
                'invalid/list' => ['tests/A', 42],
                5 => 'ignored-because-key-is-not-a-string',
                'invalid/value' => 42,
            ],
            'never_cache' => ['tests/A.php', 42, 'tests/B.php'],
        ]);

        self::assertSame(
            ['valid/string' => 'tests/Feature', 'valid/list' => ['tests/A', 'tests/B']],
            $config->watch,
        );
        self::assertSame(['tests/A.php', 'tests/B.php'], $config->neverCache);
    }

    public function testLoadReturnsDefaultsWhenThereIsNoConfigFile(): void
    {
        $projectRoot = TempDir::make('config-none');

        try {
            self::assertEquals(Config::defaults(), Config::load($projectRoot));
        } finally {
            TempDir::remove($projectRoot);
        }
    }

    public function testLoadReadsAnArrayReturningConfigFile(): void
    {
        $projectRoot = TempDir::make('config-file');

        TempDir::write($projectRoot . '/phpunit-replay.php', <<<'PHP'
            <?php

            return [
                'state_dir' => '/from/file',
                'default_branch' => 'develop',
                'quarantine_release_after' => 7,
            ];
            PHP);

        try {
            $config = Config::load($projectRoot);

            self::assertSame('/from/file', $config->stateDir);
            self::assertSame('develop', $config->defaultBranch);
            self::assertSame(7, $config->quarantineReleaseAfter);
        } finally {
            TempDir::remove($projectRoot);
        }
    }

    public function testLoadFallsBackToDefaultsWhenTheConfigFileDoesNotReturnAnArray(): void
    {
        $projectRoot = TempDir::make('config-not-array');

        TempDir::write($projectRoot . '/phpunit-replay.php', "<?php\n\nreturn 'oops';\n");

        try {
            self::assertEquals(Config::defaults(), Config::load($projectRoot));
        } finally {
            TempDir::remove($projectRoot);
        }
    }

    public function testLoadNeverThrowsWhenTheConfigFileCallsAnUndefinedEnvHelper(): void
    {
        $projectRoot = TempDir::make('config-undefined-env');

        TempDir::write($projectRoot . '/phpunit-replay.php', <<<'PHP'
            <?php

            return [
                'remote' => env('PHPUNIT_REPLAY_REMOTE'),
            ];
            PHP);

        try {
            self::assertEquals(Config::defaults(), Config::load($projectRoot));
        } finally {
            TempDir::remove($projectRoot);
        }
    }

    public function testLoadAppliesEnvironmentOverridesOnTopOfTheFile(): void
    {
        $projectRoot = TempDir::make('config-env-wins');

        TempDir::write($projectRoot . '/phpunit-replay.php', <<<'PHP'
            <?php

            return [
                'state_dir' => '/from/file',
                'default_branch' => 'develop',
            ];
            PHP);

        $keys = ['PHPUNIT_REPLAY_STATE_DIR', 'PHPUNIT_REPLAY_DEFAULT_BRANCH', 'PHPUNIT_REPLAY_MODE'];
        $previous = [];

        foreach ($keys as $key) {
            $previous[$key] = $_SERVER[$key] ?? null;
        }

        $_SERVER['PHPUNIT_REPLAY_STATE_DIR'] = '/from/env';
        $_SERVER['PHPUNIT_REPLAY_DEFAULT_BRANCH'] = 'main';
        $_SERVER['PHPUNIT_REPLAY_MODE'] = 'record-subset';

        try {
            $config = Config::load($projectRoot);

            self::assertSame('/from/env', $config->stateDir);
            self::assertSame('main', $config->defaultBranch);
            self::assertSame('record-subset', $config->mode);
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }

            TempDir::remove($projectRoot);
        }
    }

    public function testMergeEnvIgnoresAnUnknownModeValueAndKeepsTheCurrentOne(): void
    {
        $config = Config::defaults()->mergeEnv(['PHPUNIT_REPLAY_MODE' => 'not-a-real-mode']);

        self::assertSame('auto', $config->mode);
    }

    public function testMergeEnvIgnoresEmptyStrings(): void
    {
        $config = Config::defaults()->with(['stateDir' => '/kept'])->mergeEnv([
            'PHPUNIT_REPLAY_STATE_DIR' => '',
            'PHPUNIT_REPLAY_REMOTE' => '',
        ]);

        self::assertSame('/kept', $config->stateDir);
        self::assertNull($config->remote);
    }

    public function testFromExtensionParametersReadsKnownParameters(): void
    {
        $parameters = ParameterCollection::fromArray([
            'mode' => 'replay',
            'stateDir' => '/state',
            'remote' => 'https://cache.example/replay/',
            'remoteToken' => 'secret',
            'defaultBranch' => 'main',
        ]);

        $config = Config::fromExtensionParameters($parameters);

        self::assertSame('replay', $config->mode);
        self::assertSame('/state', $config->stateDir);
        self::assertSame('https://cache.example/replay/', $config->remote);
        self::assertSame('secret', $config->remoteToken);
        self::assertSame('main', $config->defaultBranch);
    }

    public function testFromExtensionParametersTreatsEmptyStringAsUnset(): void
    {
        $parameters = ParameterCollection::fromArray([
            'mode' => '',
            'stateDir' => '',
            'remote' => '',
            'remoteToken' => '',
            'defaultBranch' => '',
        ]);

        self::assertEquals(Config::defaults(), Config::fromExtensionParameters($parameters));
    }

    public function testFromExtensionParametersFallsBackToDefaultsWhenParametersAreAbsent(): void
    {
        $config = Config::fromExtensionParameters(ParameterCollection::fromArray([]));

        self::assertEquals(Config::defaults(), $config);
    }

    public function testIsKnownModeAcceptsTheFourDocumentedModesAndTheTwoInternalOnes(): void
    {
        foreach (['auto', 'record', 'replay', 'off', 'record-subset', 'results-only'] as $mode) {
            self::assertTrue(Config::isKnownMode($mode), $mode . ' should be known');
        }
    }

    public function testIsKnownModeRejectsAnythingElse(): void
    {
        self::assertFalse(Config::isKnownMode('nonsense'));
        self::assertFalse(Config::isKnownMode(''));
    }

    public function testWithReturnsACopyOverridingOnlyGivenKeys(): void
    {
        $config = Config::defaults()->with(['mode' => 'replay', 'quarantineReleaseAfter' => 3]);

        self::assertSame('replay', $config->mode);
        self::assertSame(3, $config->quarantineReleaseAfter);
        self::assertSame('auto', $config->laravel);
        self::assertTrue($config->junitMerge);
    }

    public function testWithOverridesTheRemoteKeys(): void
    {
        $config = Config::defaults()->with([
            'remote' => 'file:///mnt/cache',
            'remoteToken' => 'secret',
            'remotePush' => 'all',
            'remoteBranch' => 'cache',
            'remoteRefreshSeconds' => 10,
            'remoteTimeout' => 15,
            'baselineBranches' => ['develop'],
        ]);

        self::assertSame('file:///mnt/cache', $config->remote);
        self::assertSame('secret', $config->remoteToken);
        self::assertSame('all', $config->remotePush);
        self::assertSame('cache', $config->remoteBranch);
        self::assertSame(10, $config->remoteRefreshSeconds);
        self::assertSame(15, $config->remoteTimeout);
        self::assertSame(['develop'], $config->baselineBranches);
    }

    public function testMergeEnvReadsTheRemoteAndBaselineOverrides(): void
    {
        $config = Config::defaults()->mergeEnv([
            'PHPUNIT_REPLAY_REMOTE' => 'file:///mnt/replay-cache',
            'PHPUNIT_REPLAY_REMOTE_TOKEN' => 'from-env',
            'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all',
            'PHPUNIT_REPLAY_BASELINE_BRANCHES' => 'develop, main ,',
        ]);

        self::assertSame('file:///mnt/replay-cache', $config->remote);
        self::assertSame('from-env', $config->remoteToken);
        self::assertSame('all', $config->remotePush);
        self::assertSame(['develop', 'main'], $config->baselineBranches);
    }

    public function testMergeEnvIgnoresAnInvalidRemotePushAndAnEmptyBranchList(): void
    {
        $config = Config::defaults()
            ->with(['remotePush' => 'off', 'baselineBranches' => ['develop']])
            ->mergeEnv([
                'PHPUNIT_REPLAY_REMOTE_PUSH' => 'sometimes',
                'PHPUNIT_REPLAY_BASELINE_BRANCHES' => ' , ',
            ]);

        self::assertSame('off', $config->remotePush);
        self::assertSame(['develop'], $config->baselineBranches);
    }

    public function testBaselineCandidatesPrefersBaselineBranchesOverDefaultBranch(): void
    {
        $config = Config::defaults()->with([
            'defaultBranch' => 'main',
            'baselineBranches' => ['develop', 'main'],
        ]);

        self::assertSame(['develop', 'main'], $config->baselineCandidates('master'));
    }

    public function testBaselineCandidatesFallsBackToDefaultBranchThenToTheDetectedOne(): void
    {
        self::assertSame(['develop'], Config::defaults()->with(['defaultBranch' => 'develop'])->baselineCandidates('main'));
        self::assertSame(['main'], Config::defaults()->baselineCandidates('main'));
        self::assertSame([], Config::defaults()->baselineCandidates(''));
    }

    public function testMaskRemoteHidesEmbeddedCredentials(): void
    {
        self::assertSame('none', Config::maskRemote(null));
        self::assertSame('none', Config::maskRemote(''));
        self::assertSame('file:///mnt/cache', Config::maskRemote('file:///mnt/cache'));
        self::assertSame('https://user:***@cache.example/replay/', Config::maskRemote('https://user:hunter2@cache.example/replay/'));
    }
}
