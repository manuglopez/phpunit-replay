<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

final class DriverDetector
{
    /** pcov preferred, then xdebug. */
    public static function detect(SourceScope $scope): ?CoverageDriver
    {
        if (self::forcedNoDriver()) {
            return null;
        }

        if (PcovDriver::available()) {
            return new PcovDriver($scope);
        }

        if (XdebugDriver::available()) {
            return new XdebugDriver($scope);
        }

        return null;
    }

    /**
     * 'pcov' | 'xdebug' | null — extension loaded, regardless of enabled/mode
     * (the wrapper decides how to relaunch PHP to enable it). Honours
     * `PHPUNIT_REPLAY_FORCE_NO_DRIVER=1`, a test-only knob (SPEC §16 acceptance
     * criterion 7) that makes the package behave as if no coverage driver
     * extension were loaded at all, regardless of what is actually installed.
     */
    public static function loadedExtension(): ?string
    {
        if (self::forcedNoDriver()) {
            return null;
        }

        if (extension_loaded('pcov')) {
            return 'pcov';
        }

        if (extension_loaded('xdebug')) {
            return 'xdebug';
        }

        return null;
    }

    /** Name of what detect() would return, without needing a SourceScope. */
    public static function availableName(): ?string
    {
        if (self::forcedNoDriver()) {
            return null;
        }

        if (PcovDriver::available()) {
            return 'pcov';
        }

        if (XdebugDriver::available()) {
            return 'xdebug';
        }

        return null;
    }

    private static function forcedNoDriver(): bool
    {
        return getenv('PHPUNIT_REPLAY_FORCE_NO_DRIVER') === '1';
    }
}
