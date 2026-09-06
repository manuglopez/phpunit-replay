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

        self::assertTrue($quarantine->isQuarantined('App\Tests\FooTest::testBar'));
        self::assertTrue($quarantine->isQuarantined('App\Tests\FooTest::testBaz'));
        self::assertFalse($quarantine->isQuarantined('not an entry'));
        self::assertEqualsCanonicalizing(
            ['App\Tests\FooTest::testBar', 'App\Tests\FooTest::testBaz'],
            $quarantine->testIds(),
        );

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
}
