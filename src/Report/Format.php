<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Report;

/**
 * Small formatting helpers shared by {@see Summary} and {@see RecordSummary}.
 */
final class Format
{
    /**
     * `<1m` → `12s`; `≥1m` → `4m12s`; `≥1h` → `1h04m` (no seconds once hours are shown).
     */
    public static function duration(float $seconds): string
    {
        $total = (int) round($seconds);

        if ($total < 60) {
            return $total . 's';
        }

        if ($total < 3600) {
            $minutes = intdiv($total, 60);
            $secs = $total % 60;

            return sprintf('%dm%02ds', $minutes, $secs);
        }

        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);

        return sprintf('%dh%02dm', $hours, $minutes);
    }

    /** `round(bytes/1024)` KB, or one-decimal MB once the size reaches 1 MB. */
    public static function bytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return sprintf('%d KB', (int) round($bytes / 1024));
    }
}
