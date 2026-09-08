<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Analysis;

use Manuglopez\Replay\Analysis\FileFacts;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('static-declaration-edges')]
final class FileFactsTest extends TestCase
{
    #[Test]
    public function covers_any_body_line_is_inclusive_at_both_ends(): void
    {
        $facts = new FileFacts(true, [[10, 12], [20, 20]], [], []);

        self::assertTrue($facts->coversAnyBodyLine([10]));
        self::assertTrue($facts->coversAnyBodyLine([12]));
        self::assertTrue($facts->coversAnyBodyLine([20]));
        self::assertTrue($facts->coversAnyBodyLine([1, 5, 11]));
        self::assertFalse($facts->coversAnyBodyLine([9, 13, 19, 21]));
        self::assertFalse($facts->coversAnyBodyLine([]));
    }

    #[Test]
    public function a_file_with_no_body_range_is_declaration_only(): void
    {
        self::assertTrue((new FileFacts(true, [], ['App\Foo'], []))->declarationOnly());
        self::assertFalse((new FileFacts(true, [[3, 4]], ['App\Foo'], []))->declarationOnly());
    }

    #[Test]
    public function an_unparseable_file_is_never_declaration_only(): void
    {
        $facts = FileFacts::unparseable();

        self::assertFalse($facts->parsed);
        self::assertFalse($facts->declarationOnly());
        self::assertFalse($facts->coversAnyBodyLine([1, 2, 3]));
    }

    #[Test]
    public function it_round_trips_through_its_array_form(): void
    {
        $facts = new FileFacts(true, [[1, 2], [7, 9]], ['App\Foo'], ['App\Bar', 'App\Baz']);

        $decoded = FileFacts::fromArray($facts->toArray());

        self::assertNotNull($decoded);
        self::assertSame($facts->parsed, $decoded->parsed);
        self::assertSame($facts->bodies, $decoded->bodies);
        self::assertSame($facts->declares, $decoded->declares);
        self::assertSame($facts->references, $decoded->references);
    }

    #[Test]
    public function it_round_trips_through_json_the_way_the_cache_stores_it(): void
    {
        $facts = new FileFacts(true, [[4, 6]], ['App\Foo'], ['App\Bar']);

        $decoded = FileFacts::fromArray(json_decode((string) json_encode($facts->toArray()), true));

        self::assertNotNull($decoded);
        self::assertSame([[4, 6]], $decoded->bodies);
        self::assertSame(['App\Bar'], $decoded->references);
    }

    #[Test]
    public function from_array_rejects_anything_it_did_not_write(): void
    {
        self::assertNull(FileFacts::fromArray(null));
        self::assertNull(FileFacts::fromArray('nope'));
        self::assertNull(FileFacts::fromArray([]));
        self::assertNull(FileFacts::fromArray(['p' => 'yes']));
    }

    #[Test]
    public function from_array_drops_malformed_ranges_and_names_instead_of_throwing(): void
    {
        $decoded = FileFacts::fromArray([
            'p' => true,
            'b' => [[1, 2], [3], 'x', ['a', 'b']],
            'd' => ['App\Foo', 7, ''],
            'r' => 'not-a-list',
        ]);

        self::assertNotNull($decoded);
        self::assertSame([[1, 2]], $decoded->bodies);
        self::assertSame(['App\Foo'], $decoded->declares);
        self::assertSame([], $decoded->references);
    }
}
