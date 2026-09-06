<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Console;

use Manuglopez\Replay\Console\ExplainFormatter;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\RunList;
use Manuglopez\Replay\Select\Selection;
use PHPUnit\Framework\TestCase;

final class ExplainFormatterTest extends TestCase
{
    public function test_a_rule_reason_is_rendered_with_rule_and_trigger(): void
    {
        self::assertSame(
            sprintf('%-40s ← %-8s %s', 'tests/FooTest.php', 'PhpEdge', 'src/Foo.php'),
            ExplainFormatter::line('tests/FooTest.php', new Reason('PhpEdge', 'src/Foo.php')),
        );
    }

    public function test_a_detail_is_rendered_in_parentheses(): void
    {
        self::assertSame(
            sprintf('%-40s ← %-8s %s', 'tests/FooTest.php', 'Watch', 'config/app.php (config/**)'),
            ExplainFormatter::line('tests/FooTest.php', new Reason('Watch', 'config/app.php', 'config/**')),
        );
    }

    public function test_a_file_without_a_reason_still_gets_a_line(): void
    {
        self::assertSame(
            sprintf('%-40s ← %-8s %s', 'tests/FooTest.php', '', ''),
            ExplainFormatter::line('tests/FooTest.php', null),
        );
    }

    public function test_lines_are_sorted_and_use_the_first_reason_of_each_file(): void
    {
        $selection = new Selection();
        $selection->add('tests/ZooTest.php', new Reason('PhpEdge', 'src/Zoo.php'));

        $list = new RunList($selection, ['tests/AlphaTest.php'], ['tests/RerunTest.php'], [], ['tests/RerunTest.php' => 7]);

        self::assertSame(
            [
                sprintf('%-40s ← %-8s %s', 'tests/AlphaTest.php', 'Uncached', 'new test file'),
                sprintf('%-40s ← %-8s %s', 'tests/RerunTest.php', 'Rerun', 'failure'),
                sprintf('%-40s ← %-8s %s', 'tests/ZooTest.php', 'PhpEdge', 'src/Zoo.php'),
            ],
            (new ExplainFormatter())->lines($list),
        );
    }

    public function test_an_explicit_file_list_overrides_the_run_list(): void
    {
        $list = new RunList(new Selection(), [], [], []);

        self::assertSame(
            [sprintf('%-40s ← %-8s %s', 'tests/OtherTest.php', '', '')],
            (new ExplainFormatter())->lines($list, ['tests/OtherTest.php']),
        );
    }
}
