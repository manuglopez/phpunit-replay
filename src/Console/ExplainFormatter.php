<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console;

use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\RunList;

/**
 * The `--explain` / `explain <path>` rendering (SPEC.md §11): one line per test file,
 * sorted by path, naming the rule that pulled it in and what triggered it.
 *
 *   tests/Feature/CartTest.php               ← PhpEdge  src/Cart.php
 *   tests/Unit/NewThingTest.php              ← Uncached new test file
 */
final class ExplainFormatter
{
    /**
     * One line per file of the run list, sorted by file. Only the first reason of each
     * file is shown — it is the one the rule chain resolved first.
     *
     * @param list<string>|null $files defaults to the whole run list
     * @param list<string> $servedFromRemote files of `$files` that a remote object stands in
     *   for instead of running them: they stay in the plan with the reason they were selected
     *   for, marked, rather than disappearing from it
     * @return list<string>
     */
    public function lines(RunList $runList, ?array $files = null, array $servedFromRemote = []): array
    {
        $files ??= $runList->files();
        sort($files);
        $served = array_fill_keys($servedFromRemote, true);

        $lines = [];

        foreach ($files as $file) {
            $reasons = $runList->reasonsFor($file);
            $line = self::line($file, $reasons[0] ?? null);
            $lines[] = isset($served[$file]) ? $line . ' [served from remote]' : $line;
        }

        return $lines;
    }

    public static function line(string $file, ?Reason $reason): string
    {
        if ($reason === null) {
            return sprintf('%-40s ← %-8s %s', $file, '', '');
        }

        return sprintf('%-40s ← %-8s %s', $file, $reason->rule, self::triggerText($reason->trigger, $reason->detail));
    }

    private static function triggerText(string $trigger, string $detail): string
    {
        return $detail === '' ? $trigger : sprintf('%s (%s)', $trigger, $detail);
    }
}
