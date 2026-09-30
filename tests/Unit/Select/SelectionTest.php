<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Selection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase
{
    public function test_a_file_selected_only_by_php_edges_and_its_own_change_is_covered_by_the_content_key(): void
    {
        $selection = new Selection();
        $selection->add('tests/FooTest.php', new Reason('PhpEdge', 'src/Foo.php'));
        $selection->add('tests/FooTest.php', new Reason('TestFile', 'tests/FooTest.php'));

        self::assertTrue($selection->coveredByContentKey('tests/FooTest.php'));
    }

    #[DataProvider('rulesTheKeyDoesNotSee')]
    public function test_one_reason_outside_the_content_key_is_enough_to_disqualify_the_file(string $rule): void
    {
        $selection = new Selection();
        $selection->add('tests/FooTest.php', new Reason('PhpEdge', 'src/Foo.php'));
        $selection->add('tests/FooTest.php', new Reason($rule, 'data/names.txt'));

        self::assertFalse($selection->coveredByContentKey('tests/FooTest.php'));
    }

    /** @return iterable<string, array{string}> */
    public static function rulesTheKeyDoesNotSee(): iterable
    {
        yield 'watch (also the residue fallback)' => ['Watch'];
        yield 'migration' => ['Migration'];
        yield 'blade' => ['Blade'];
        yield 'sibling' => ['Sibling'];
        yield 'a rule added tomorrow' => ['SomethingNew'];
    }

    public function test_an_unselected_file_is_not_covered(): void
    {
        self::assertFalse((new Selection())->coveredByContentKey('tests/FooTest.php'));
    }
}
