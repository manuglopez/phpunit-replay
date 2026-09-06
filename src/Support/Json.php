<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Support;

use JsonException;

/**
 * A JSON codec that never throws: encode/decode failures come back as null so callers can
 * degrade gracefully (docs/INTERNALS.md — "never throw out of a public method for an
 * environmental problem").
 */
final class Json
{
    /** JSON_UNESCAPED_SLASHES. null on failure. */
    public static function encode(mixed $value): ?string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /** JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT. null on failure. */
    public static function encodePretty(mixed $value): ?string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /** @return array<mixed>|null null when invalid or not an object/array */
    public static function decodeArray(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
