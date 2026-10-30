<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** The `test_translations` section of the graph (Laravel, `translations` config). */
final class GraphTranslationsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('graph-translations');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    #[Group('AC-I1-a')]
    public function test_union_normalises_sorts_and_dedups_the_entries(): void
    {
        $graph = new Graph($this->root);
        $graph->unionTestTranslations([
            'tests/AT.php' => ['es:messages.b', 'en:messages.a', 'en:messages.a', '', 'es:messages.b'],
        ]);

        self::assertSame(['tests/AT.php' => ['en:messages.a', 'es:messages.b']], $graph->testTranslations());
        self::assertSame(['en:messages.a', 'es:messages.b'], $graph->translationsOf('tests/AT.php'));
    }

    #[Group('AC-I1-c')]
    public function test_union_keeps_what_earlier_recordings_saw(): void
    {
        $graph = new Graph($this->root);
        $graph->unionTestTranslations(['tests/AT.php' => ['en:a']]);
        $graph->unionTestTranslations(['tests/BT.php' => ['en:b'], 'tests/AT.php' => ['en:z']]);

        self::assertSame(['en:a', 'en:z'], $graph->translationsOf('tests/AT.php'));
        self::assertSame(['en:b'], $graph->translationsOf('tests/BT.php'));
    }

    public function test_a_file_outside_the_project_root_is_ignored(): void
    {
        $graph = new Graph($this->root);
        $graph->unionTestTranslations(['/elsewhere/tests/OutTest.php' => ['en:a']]);

        self::assertSame([], $graph->testTranslations());
    }

    public function test_absolute_paths_are_stored_relative_to_the_project_root(): void
    {
        $graph = new Graph($this->root);
        $graph->unionTestTranslations([$this->root . '/tests/AT.php' => ['en:a']]);

        self::assertSame(['tests/AT.php' => ['en:a']], $graph->testTranslations());
    }

    #[Group('AC-I1-e')]
    public function test_star_marks_a_file_untrackable_and_is_not_one_of_its_translations(): void
    {
        $graph = new Graph($this->root);
        $graph->unionTestTranslations(['tests/SwapTest.php' => ['*', 'en:a'], 'tests/PlainTest.php' => ['en:b']]);

        self::assertTrue($graph->translationsUntrackable('tests/SwapTest.php'));
        self::assertSame(['en:a'], $graph->translationsOf('tests/SwapTest.php'));
        self::assertFalse($graph->translationsUntrackable('tests/PlainTest.php'));
        self::assertFalse($graph->translationsUntrackable('tests/UnknownTest.php'));
        self::assertSame([], $graph->translationsOf('tests/UnknownTest.php'));
    }

    #[Group('AC-I1-c')]
    public function test_star_is_what_the_latest_recording_of_each_executed_file_says(): void
    {
        // A recording made with a swapped translator must not make the file untrackable forever.
        $graph = new Graph($this->root);
        $graph->unionTestTranslations(['tests/AT.php' => ['*', 'en:a'], 'tests/BT.php' => ['*'], 'tests/CT.php' => ['*', 'en:c']]);

        $graph->unionTestTranslations(['tests/AT.php' => ['en:a2']], ['tests/AT.php', 'tests/BT.php']);

        self::assertFalse($graph->translationsUntrackable('tests/AT.php'), 'A ran again without a swap');
        self::assertSame(['en:a', 'en:a2'], $graph->translationsOf('tests/AT.php'), 'keys still accumulate');
        self::assertArrayNotHasKey('tests/BT.php', $graph->testTranslations(), 'B ran, nothing left to say');
        self::assertTrue($graph->translationsUntrackable('tests/CT.php'), 'C did not run: unchanged');
    }

    #[Group('AC-I1-c')]
    public function test_an_executed_file_that_keeps_its_star_stays_untrackable(): void
    {
        $graph = new Graph($this->root);
        $graph->unionTestTranslations(['tests/AT.php' => ['*']]);
        $graph->unionTestTranslations(['tests/AT.php' => ['*', 'en:a']], ['tests/AT.php']);

        self::assertTrue($graph->translationsUntrackable('tests/AT.php'));
        self::assertSame(['en:a'], $graph->translationsOf('tests/AT.php'));
    }

    #[Group('AC-I1-c')]
    public function test_it_round_trips_through_encode_and_decode_sorted(): void
    {
        $graph = new Graph($this->root);
        $graph->unionTestTranslations(['tests/ZT.php' => ['es:b', '*', 'en:a'], 'tests/AT.php' => ['en:c']]);

        $json = $graph->encode();
        self::assertIsString($json);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            ['tests/AT.php' => ['en:c'], 'tests/ZT.php' => ['*', 'en:a', 'es:b']],
            $payload['test_translations'],
        );

        $decoded = Graph::decode($json, $this->root);
        self::assertNotNull($decoded);
        self::assertSame($graph->testTranslations(), $decoded->testTranslations());
        self::assertTrue($decoded->translationsUntrackable('tests/ZT.php'));
    }

    public function test_the_section_is_omitted_when_empty_and_decodes_to_empty_without_it(): void
    {
        $graph = new Graph($this->root);
        $json = $graph->encode();
        self::assertIsString($json);
        self::assertArrayNotHasKey('test_translations', json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        $decoded = Graph::decode($json, $this->root);
        self::assertNotNull($decoded);
        self::assertSame([], $decoded->testTranslations());
    }

    #[Group('AC-I1-c')]
    public function test_prune_drops_the_translations_of_deleted_test_files(): void
    {
        mkdir($this->root . '/tests', 0777, true);
        file_put_contents($this->root . '/tests/KeepTest.php', '<?php');

        $graph = new Graph($this->root);
        $graph->unionTestTranslations(['tests/KeepTest.php' => ['en:keep'], 'tests/GoneTest.php' => ['en:gone', '*']]);

        $graph->pruneMissingTestFiles();

        self::assertSame(['tests/KeepTest.php' => ['en:keep']], $graph->testTranslations());
        self::assertFalse($graph->translationsUntrackable('tests/GoneTest.php'));
    }

    #[Group('AC-I1-c')]
    public function test_stats_count_the_distinct_entries_without_the_star(): void
    {
        $graph = new Graph($this->root);
        self::assertSame(0, $graph->stats()['translations']);

        $graph->unionTestTranslations([
            'tests/AT.php' => ['en:a', 'es:a', '*'],
            'tests/BT.php' => ['en:a', 'en:b'],
            'tests/CT.php' => ['*'],
        ]);

        self::assertSame(3, $graph->stats()['translations']);
    }
}
