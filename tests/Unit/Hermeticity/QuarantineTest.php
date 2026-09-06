<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Hermeticity;

use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class QuarantineTest extends TestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stateDir = TempDir::make('quarantine');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->stateDir);

        parent::tearDown();
    }

    public function test_loading_a_missing_file_yields_an_empty_quarantine(): void
    {
        $quarantine = Quarantine::load($this->stateDir);

        self::assertSame([], $quarantine->all());
        self::assertSame([], $quarantine->testIds());
        self::assertFalse($quarantine->isQuarantined('App\Tests\FooTest::testBar'));
    }

    public function test_loading_invalid_json_yields_an_empty_quarantine(): void
    {
        TempDir::write($this->stateDir . '/flaky.json', '{not json');

        self::assertSame([], Quarantine::load($this->stateDir)->all());
    }

    public function test_entries_are_read_back_with_defaults_for_missing_fields(): void
    {
        TempDir::write($this->stateDir . '/flaky.json', json_encode([
            'App\Tests\FooTest::testBar' => ['firstSeen' => 17, 'flips' => 2, 'stable' => 1, 'lastKey' => 'abc', 'reason' => 'divergence'],
            'App\Tests\FooTest::testBaz' => ['flips' => 'nope'],
            'not an entry' => 'string',
        ], JSON_THROW_ON_ERROR));

        $quarantine = Quarantine::load($this->stateDir);

        // testBar has flips=2: quarantined. testBaz's 'flips' is malformed and defaults
        // to 0: known (present in all()) but not currently quarantined.
        self::assertTrue($quarantine->isQuarantined('App\Tests\FooTest::testBar'));
        self::assertFalse($quarantine->isQuarantined('App\Tests\FooTest::testBaz'));
        self::assertFalse($quarantine->isQuarantined('not an entry'));
        self::assertSame(['App\Tests\FooTest::testBar'], $quarantine->testIds());

        $all = $quarantine->all();
        self::assertSame(
            ['firstSeen' => 17, 'flips' => 2, 'stable' => 1, 'lastKey' => 'abc', 'reason' => 'divergence'],
            $all['App\Tests\FooTest::testBar'],
        );
        self::assertSame(
            ['firstSeen' => 0, 'flips' => 0, 'stable' => 0, 'lastKey' => '', 'reason' => 'flip'],
            $all['App\Tests\FooTest::testBaz'],
        );
    }

    public function test_save_round_trips_and_clear_empties_the_file(): void
    {
        TempDir::write($this->stateDir . '/flaky.json', json_encode([
            'App\Tests\FooTest::testBar' => ['firstSeen' => 17, 'flips' => 2, 'stable' => 1, 'lastKey' => 'abc', 'reason' => 'flip'],
        ], JSON_THROW_ON_ERROR));

        $quarantine = Quarantine::load($this->stateDir);
        self::assertTrue($quarantine->save($this->stateDir . '-copy'));

        $copy = Quarantine::load($this->stateDir . '-copy');
        self::assertSame($quarantine->all(), $copy->all());

        $copy->clear();
        self::assertTrue($copy->save($this->stateDir . '-copy'));
        self::assertSame([], Quarantine::load($this->stateDir . '-copy')->all());

        TempDir::remove($this->stateDir . '-copy');
    }

    public function test_record_flip_creates_an_entry_and_resets_the_stability_streak(): void
    {
        $quarantine = new Quarantine();

        $quarantine->recordFlip('App\Tests\FooTest::testBar', 'key-1');

        self::assertTrue($quarantine->isQuarantined('App\Tests\FooTest::testBar'));

        $entry = $quarantine->all()['App\Tests\FooTest::testBar'];
        self::assertSame(1, $entry['flips']);
        self::assertSame(0, $entry['stable']);
        self::assertSame('key-1', $entry['lastKey']);
        self::assertSame('flip', $entry['reason']);

        $quarantine->recordStable('App\Tests\FooTest::testBar');
        $quarantine->recordFlip('App\Tests\FooTest::testBar', 'key-2', 'divergence');

        $entry = $quarantine->all()['App\Tests\FooTest::testBar'];
        self::assertSame(2, $entry['flips']);
        self::assertSame(0, $entry['stable']);
        self::assertSame('key-2', $entry['lastKey']);
        self::assertSame('divergence', $entry['reason']);
    }

    public function test_record_stable_on_an_unknown_id_is_a_no_op(): void
    {
        $quarantine = new Quarantine();

        $quarantine->recordStable('App\Tests\FooTest::testBar');

        self::assertSame([], $quarantine->all());
    }

    public function test_release_after_enough_consecutive_stable_passes(): void
    {
        $quarantine = new Quarantine();
        $quarantine->setReleaseAfter(2);
        $quarantine->recordFlip('App\Tests\FooTest::testBar', 'key-1');

        self::assertTrue($quarantine->isQuarantined('App\Tests\FooTest::testBar'));

        $quarantine->recordStable('App\Tests\FooTest::testBar');
        self::assertTrue($quarantine->isQuarantined('App\Tests\FooTest::testBar'));

        $quarantine->recordStable('App\Tests\FooTest::testBar');
        self::assertFalse($quarantine->isQuarantined('App\Tests\FooTest::testBar'));
    }

    public function test_release_clears_flips_but_keeps_the_entrys_history(): void
    {
        $quarantine = new Quarantine();
        $quarantine->recordFlip('App\Tests\FooTest::testBar', 'key-1');

        $quarantine->release('App\Tests\FooTest::testBar');

        self::assertFalse($quarantine->isQuarantined('App\Tests\FooTest::testBar'));
        self::assertArrayHasKey('App\Tests\FooTest::testBar', $quarantine->all());
        self::assertSame(0, $quarantine->all()['App\Tests\FooTest::testBar']['flips']);
    }

    public function test_release_on_an_unknown_id_is_a_no_op(): void
    {
        $quarantine = new Quarantine();

        $quarantine->release('App\Tests\FooTest::testBar');

        self::assertSame([], $quarantine->all());
    }
}
