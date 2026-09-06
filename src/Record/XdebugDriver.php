<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

/**
 * Raw Xdebug coverage driver (mode=coverage). SPEC §5.1.
 */
final class XdebugDriver implements CoverageDriver
{
    public function __construct(private readonly SourceScope $scope)
    {
    }

    public static function available(): bool
    {
        if (! function_exists('xdebug_start_code_coverage') || ! function_exists('xdebug_info')) {
            return false;
        }

        $modes = xdebug_info('mode');

        return is_array($modes) && in_array('coverage', $modes, true);
    }

    public function name(): string
    {
        return 'xdebug';
    }

    public function start(): void
    {
        xdebug_start_code_coverage();
    }

    /** @return array<string, array<int, int>> */
    public function stop(): array
    {
        $data = xdebug_get_code_coverage();
        xdebug_stop_code_coverage(true);

        foreach (array_keys($data) as $file) {
            if (! $this->scope->contains($file)) {
                unset($data[$file]);
            }
        }

        return $data;
    }
}
