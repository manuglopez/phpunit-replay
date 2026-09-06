<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Support;

use Manuglopez\Replay\Support\Json;
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{
    public function testEncodeProducesUnescapedSlashes(): void
    {
        self::assertSame('{"path":"a/b"}', Json::encode(['path' => 'a/b']));
    }

    public function testEncodeReturnsNullForUnencodableValue(): void
    {
        self::assertNull(Json::encode(NAN));
    }

    public function testEncodePrettyIsMultiline(): void
    {
        $encoded = Json::encodePretty(['a' => 1]);

        self::assertNotNull($encoded);
        self::assertStringContainsString("\n", $encoded);
    }

    public function testDecodeArrayReturnsArrayForValidJson(): void
    {
        self::assertSame(['a' => 1], Json::decodeArray('{"a":1}'));
        self::assertSame([1, 2, 3], Json::decodeArray('[1,2,3]'));
    }

    public function testDecodeArrayReturnsNullForInvalidJson(): void
    {
        self::assertNull(Json::decodeArray('{invalid'));
    }

    public function testDecodeArrayReturnsNullForNonArrayJson(): void
    {
        self::assertNull(Json::decodeArray('"just a string"'));
        self::assertNull(Json::decodeArray('42'));
    }
}
