<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

interface CoverageDriver
{
    /** Can this driver record right now, in this process? */
    public static function available(): bool;

    /** 'pcov' | 'xdebug' */
    public function name(): string;

    public function start(): void;

    /** @return array<string, array<int, int>> absolute file => [line => hits], already filtered by SourceScope */
    public function stop(): array;
}
