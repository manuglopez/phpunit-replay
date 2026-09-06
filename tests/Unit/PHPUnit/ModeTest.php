<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use Manuglopez\Replay\PHPUnit\Mode;
use PHPUnit\Framework\TestCase;

final class ModeTest extends TestCase
{
    public function test_backed_values_match_the_wire_format(): void
    {
        self::assertSame('record', Mode::Record->value);
        self::assertSame('record-subset', Mode::RecordSubset->value);
        self::assertSame('results-only', Mode::ResultsOnly->value);
        self::assertSame('replay', Mode::Replay->value);
        self::assertSame('off', Mode::Off->value);
    }

    public function test_records_edges_is_true_only_for_record_and_record_subset(): void
    {
        self::assertTrue(Mode::Record->recordsEdges());
        self::assertTrue(Mode::RecordSubset->recordsEdges());
        self::assertFalse(Mode::ResultsOnly->recordsEdges());
        self::assertFalse(Mode::Replay->recordsEdges());
        self::assertFalse(Mode::Off->recordsEdges());
    }

    public function test_try_from_env_parses_known_values(): void
    {
        self::assertSame(Mode::Record, Mode::tryFromEnv('record'));
        self::assertSame(Mode::RecordSubset, Mode::tryFromEnv('record-subset'));
        self::assertSame(Mode::ResultsOnly, Mode::tryFromEnv('results-only'));
        self::assertSame(Mode::Replay, Mode::tryFromEnv('replay'));
        self::assertSame(Mode::Off, Mode::tryFromEnv('off'));
    }

    public function test_try_from_env_returns_null_for_null_empty_or_unknown_values(): void
    {
        self::assertNull(Mode::tryFromEnv(null));
        self::assertNull(Mode::tryFromEnv(''));
        self::assertNull(Mode::tryFromEnv('bogus'));
    }
}
