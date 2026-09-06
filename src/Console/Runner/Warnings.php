<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console\Runner;

/**
 * STDERR writer for the wrapper's own diagnostics: user-facing warnings, always prefixed
 * `phpunit-replay: `, and `[replay] `-prefixed debug lines gated behind `PHPUNIT_REPLAY_DEBUG`.
 * docs/INTERNALS.md steps 10-11.
 */
final class Warnings
{
    public static function warn(string $message): void
    {
        fwrite(STDERR, 'phpunit-replay: ' . $message . PHP_EOL);
    }

    public static function debug(string $message): void
    {
        if (self::debugEnabled()) {
            fwrite(STDERR, '[replay] ' . $message . PHP_EOL);
        }
    }

    public static function debugEnabled(): bool
    {
        return getenv('PHPUNIT_REPLAY_DEBUG') === '1';
    }
}
