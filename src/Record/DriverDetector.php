<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

final class DriverDetector
{
    /** pcov preferred, then xdebug. */
    public static function detect(SourceScope $scope): ?CoverageDriver
    {
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
     * (the wrapper decides how to relaunch PHP to enable it).
     */
    public static function loadedExtension(): ?string
    {
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
        if (PcovDriver::available()) {
            return 'pcov';
        }

        if (XdebugDriver::available()) {
            return 'xdebug';
        }

        return null;
    }
}
