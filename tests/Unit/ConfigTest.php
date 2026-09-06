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
}
