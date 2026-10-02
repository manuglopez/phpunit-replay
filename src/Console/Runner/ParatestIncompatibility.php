<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

use Composer\InstalledVersions;
use PHPUnit\Runner\Version;

/**
 * Recognises the one way a ParaTest run dies that is nobody's test failure: ParaTest
 * constructs a PHPUnit internal the way an older PHPUnit declared it, and PHP throws
 * `ArgumentCountError: Too few arguments to function PHPUnit\TextUI\…::__construct()` from
 * inside ParaTest, exit 255. That happened when PHPUnit 13.4 gave several `TextUI` classes
 * a required constructor argument while ParaTest (7.25.0, which still accepts `phpunit/phpunit
 * ^13.3.4`) kept constructing them bare.
 *
 * The fatal is bare PHP output; this turns it into a sentence saying whose problem it is. It
 * only EXPLAINS: the exit code is untouched (255 is not a completed pass, see
 * {@see RunPipeline::ranToTheEnd()}, so nothing is recorded or replayed from it).
 */
final class ParatestIncompatibility
{
    /** Bytes of ParaTest output kept for matching: the fatal is the last thing it prints. */
    public const TAIL_BYTES = 65536;

    public static function detect(int $exitCode, string $output): ?string
    {
        return self::message(
            $exitCode,
            $output,
            self::installed('brianium/paratest'),
            self::installed('phpunit/phpunit') ?? Version::id(),
        );
    }

    public static function message(int $exitCode, string $output, ?string $paratestVersion, ?string $phpunitVersion): ?string
    {
        if ($exitCode !== 255
            || ! str_contains($output, 'ArgumentCountError')
            || ! str_contains($output, 'PHPUnit\\TextUI\\')
        ) {
            return null;
        }

        $paratest = $paratestVersion ?? 'unknown version';
        $phpunit = $phpunitVersion ?? 'unknown version';

        return sprintf(
            'ParaTest %s cannot run on PHPUnit %s (an upstream incompatibility, not a test failure). '
            . 'Run without --parallel, or pin phpunit/phpunit below %s until ParaTest supports it.',
            $paratest,
            $phpunit,
            $phpunit,
        );
    }

    private static function installed(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class) || ! InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package);
    }
}
