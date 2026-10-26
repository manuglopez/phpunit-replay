<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Console;

use Manuglopez\Replay\Console\StatusReport;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class StatusReportTest extends TestCase
{
    #[Group('AC-I1-c')]
    public function test_the_translations_counter_follows_the_tables_one(): void
    {
        $lines = $this->report(hasBaseline: true, translations: 3)->lines();

        $tables = array_search('tables:     2', $lines, true);

        self::assertNotFalse($tables);
        self::assertSame('translations: 3', $lines[$tables + 1]);
    }

    #[Group('AC-I1-c')]
    public function test_the_translations_counter_defaults_to_zero(): void
    {
        self::assertContains('translations: 0', $this->report(hasBaseline: true)->lines());
    }

    #[Group('AC-I1-c')]
    public function test_there_is_no_translations_line_without_a_baseline(): void
    {
        $lines = $this->report(hasBaseline: false, translations: 3)->lines();

        self::assertNotContains('translations: 3', $lines);
        self::assertContains('no baseline yet', $lines);
    }

    private function report(bool $hasBaseline, ?int $translations = null): StatusReport
    {
        $arguments = [
            'root' => '/project',
            'branch' => 'feature',
            'defaultBranch' => 'main',
            'headSha' => str_repeat('a', 40),
            'stateDir' => '/state',
            'driverLine' => 'pcov',
            'framework' => 'phpunit',
            'hasBaseline' => $hasBaseline,
            'branches' => [],
            'files' => 10,
            'testFiles' => 4,
            'edges' => 20,
            'tables' => 2,
            'graphBytes' => 1024,
            'graphFingerprint' => null,
            'currentFingerprint' => [],
            'quarantined' => 0,
        ];

        if ($translations !== null) {
            $arguments['translations'] = $translations;
        }

        return new StatusReport(...$arguments);
    }
}
