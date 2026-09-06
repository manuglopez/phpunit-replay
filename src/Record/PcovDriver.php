<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

/**
 * Raw pcov coverage driver. SPEC §5.1.
 */
final class PcovDriver implements CoverageDriver
{
    public function __construct(private readonly SourceScope $scope)
    {
    }

    public static function available(): bool
    {
        return function_exists('pcov\\start')
            && filter_var((string) ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL);
    }

    public function name(): string
    {
        return 'pcov';
    }

    public function start(): void
    {
        \pcov\clear();
        \pcov\start();
    }

    /** @return array<string, array<int, int>> */
    public function stop(): array
    {
        \pcov\stop();

        $waiting = \pcov\waiting();

        $files = array_values(array_filter($waiting, $this->scope->contains(...)));

        return \pcov\collect(\pcov\inclusive, $files);
    }
}
