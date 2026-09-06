<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Support\Paths;
use PHPUnit\TextUI\CliArguments\Builder as CliArgumentsBuilder;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Merger;
use PHPUnit\TextUI\XmlConfiguration\Loader as XmlConfigurationLoader;

/**
 * docs/INTERNALS.md "Wrapper pipeline" steps 1 (root & tools) and 4 (PHPUnit configuration
 * object). Never throws: every resolution failure is reported as `null`, letting
 * {@see RunPipeline} degrade.
 */
final class ProjectLocator
{
    public function resolveRoot(Git $git): ?string
    {
        return $git->topLevel();
    }

    /** `<root>/vendor/bin/phpunit`, or `PHPUNIT_REPLAY_PHPUNIT_BIN` when set. Null when missing. */
    public function resolvePhpunitBin(string $root): ?string
    {
        $override = getenv('PHPUNIT_REPLAY_PHPUNIT_BIN');
        $bin = (is_string($override) && $override !== '') ? $override : $root . '/vendor/bin/phpunit';

        return is_file($bin) ? $bin : null;
    }

    /**
     * `-c|--configuration <file>` from `$phpunitArgs`, else `<root>/phpunit.xml`, else
     * `<root>/phpunit.xml.dist`. Null when none of those resolve to a real file.
     *
     * @param list<string> $phpunitArgs
     */
    public function resolveConfigFile(string $root, array $phpunitArgs): ?string
    {
        $explicit = self::explicitConfigurationOption($phpunitArgs);

        if ($explicit !== null) {
            $path = Paths::isAbsolute($explicit) ? $explicit : Paths::join($root, $explicit);

            return is_file($path) ? $path : null;
        }

        if (is_file($root . '/phpunit.xml')) {
            return $root . '/phpunit.xml';
        }

        if (is_file($root . '/phpunit.xml.dist')) {
            return $root . '/phpunit.xml.dist';
        }

        return null;
    }

    /**
     * Builds a `Configuration` from the XML file plus CLI-style arguments, in the wrapper
     * process itself, without ever touching `PHPUnit\TextUI\Configuration\Registry` (which
     * belongs to whatever process eventually runs PHPUnit, not this one).
     *
     * @param list<string> $cliArguments
     */
    public function buildConfiguration(string $configFile, array $cliArguments): Configuration
    {
        $xml = (new XmlConfigurationLoader())->load($configFile);

        $cli = (new CliArgumentsBuilder())->fromParameters([
            '--configuration',
            $configFile,
            ...$cliArguments,
        ]);

        return (new Merger())->merge($cli, $xml);
    }

    /** @param list<string> $phpunitArgs */
    private static function explicitConfigurationOption(array $phpunitArgs): ?string
    {
        $count = count($phpunitArgs);

        for ($i = 0; $i < $count; $i++) {
            $arg = $phpunitArgs[$i];

            if (($arg === '-c' || $arg === '--configuration') && isset($phpunitArgs[$i + 1])) {
                return $phpunitArgs[$i + 1];
            }

            if (str_starts_with($arg, '--configuration=')) {
                return substr($arg, strlen('--configuration='));
            }
        }

        return null;
    }
}
