<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel;

use JsonException;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error as ParseError;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use stdClass;
use UnexpectedValueException;

/**
 * Language files, read without running them: which project paths are translations, a
 * translation file flattened to `key => canonical value`, and the keys that differ between
 * two versions of it (`Rules\TranslationRule`, `Select\NonEdgeInputs`).
 *
 * Nothing the project wrote is ever executed: a `.php` file is parsed and only accepted when
 * it is `return <array literal>;` (optionally after `declare(...);`) whose keys and leaves
 * are constant expressions made of literals. A function call, a constant, a variable, an
 * `include`, an interpolated string or a syntax error makes the file "not comparable":
 * `flatten()` and `changedKeys()` return null and the caller falls back to its broad rule.
 *
 * Keys: a JSON file is flat and each key is literal (`Welcome, :name.`); a PHP file's keys
 * are the group (`admin/users` for `lang/es/admin/users.php`), a dot, then the nesting joined
 * by dots (`admin/users.title.short`), as `trans()` spells them.
 */
final class LangFiles
{
    /** Where Laravel keeps language files: `lang/` since 9, `resources/lang/` before. */
    public const ROOTS = ['lang', 'resources/lang'];

    private const VENDOR_DIRECTORY = 'vendor';

    /** null when the path is not under a language root. */
    public static function classify(string $rel): ?LangPath
    {
        foreach (self::ROOTS as $root) {
            if (! str_starts_with($rel, $root . '/')) {
                continue;
            }

            $rest = substr($rel, strlen($root) + 1);

            return $rest === '' ? null : self::classifyUnderRoot($rest);
        }

        return null;
    }

    /**
     * The file as `key => canonical value` (the JSON encoding of the value, so a change of
     * type counts), or null when it cannot be read without running code: invalid or non
     * object JSON, PHP that is not a literal array, a file that is not a translation file, or
     * PHP in which two entries flatten to the same key (`'a.b'` and `'a' => ['b']`).
     *
     * @return array<string, string>|null
     */
    public static function flatten(string $content, LangPath $path): ?array
    {
        return match ($path->kind) {
            LangPath::JSON => self::flattenJson($content),
            LangPath::PHP => $path->group === null ? null : self::flattenPhp($content, $path->group),
            default => null,
        };
    }

    /**
     * The keys added, deleted or given another value between two versions, sorted and unique.
     * A null side is an absent file (no entries); creating or deleting a PHP file also names
     * its group, which a test may ask for whole (`trans('messages')`). Null when a present
     * side does not flatten.
     *
     * @return list<string>|null
     */
    public static function changedKeys(?string $old, ?string $new, LangPath $path): ?array
    {
        $before = $old === null ? [] : self::flatten($old, $path);
        $after = $new === null ? [] : self::flatten($new, $path);

        if ($before === null || $after === null) {
            return null;
        }

        $changed = [];

        foreach ($before as $key => $value) {
            if (($after[$key] ?? null) !== $value) {
                $changed[$key] = true;
            }
        }

        foreach ($after as $key => $_) {
            if (! isset($before[$key])) {
                $changed[$key] = true;
            }
        }

        if ($path->kind === LangPath::PHP && ($old === null) !== ($new === null)) {
            $changed[(string) $path->group] = true;
        }

        $keys = array_map(strval(...), array_keys($changed));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Whether a test that read `$recordedKey` is affected by a change to `$changedKey`: the
     * same key, or one is the other's ancestor (`a.b` ↔ `a.b.c`, either way round: a test
     * that asked for a group or a subtree reads everything under it). The separator is a
     * dot, so `a.b` does not match `a.bc`.
     */
    public static function matches(string $recordedKey, string $changedKey): bool
    {
        return $recordedKey === $changedKey
            || str_starts_with($changedKey, $recordedKey . '.')
            || str_starts_with($recordedKey, $changedKey . '.');
    }

    /**
     * The entries of a flattened file equal to `$key` or under it (`$key.` prefix), in the
     * file's order.
     *
     * @param array<string, string> $flat
     * @return array<string, string>
     */
    public static function subtree(array $flat, string $key): array
    {
        $prefix = $key . '.';
        $out = [];

        foreach ($flat as $name => $value) {
            $name = (string) $name;

            if ($name === $key || str_starts_with($name, $prefix)) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** @param non-empty-string $rest the path under the root */
    private static function classifyUnderRoot(string $rest): LangPath
    {
        $segments = explode('/', $rest);

        if (in_array('', $segments, true)) {
            return new LangPath(LangPath::OTHER, null, null);
        }

        if (count($segments) > 1 && $segments[0] === self::VENDOR_DIRECTORY) {
            return new LangPath(LangPath::VENDOR, null, null);
        }

        if (count($segments) === 1) {
            $locale = self::stem($segments[0], '.json');

            return $locale === null
                ? new LangPath(LangPath::OTHER, null, null)
                : new LangPath(LangPath::JSON, $locale, null);
        }

        $locale = array_shift($segments);
        $file = self::stem((string) array_pop($segments), '.php');

        return $file === null
            ? new LangPath(LangPath::OTHER, null, null)
            : new LangPath(LangPath::PHP, $locale, implode('/', [...$segments, $file]));
    }

    /** The file name without `$extension`, null when it does not end in it or has no name before it. */
    private static function stem(string $name, string $extension): ?string
    {
        return str_ends_with($name, $extension) && strlen($name) > strlen($extension)
            ? substr($name, 0, -strlen($extension))
            : null;
    }

    /** The canonical value: `1` and `1.0` differ, and so does `1` and `"1"`. Null when it cannot be encoded (INF). */
    private static function encode(mixed $value): ?string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /** @return array<string, string>|null */
    private static function flattenJson(string $content): ?array
    {
        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! $decoded instanceof stdClass) {
            return null;
        }

        $flat = [];

        foreach (get_object_vars($decoded) as $key => $value) {
            $encoded = self::encode($value);

            if ($encoded === null) {
                return null;
            }

            $flat[(string) $key] = $encoded;
        }

        return $flat;
    }

    /** @return array<string, string>|null */
    private static function flattenPhp(string $content, string $group): ?array
    {
        try {
            $array = self::returnedArray($content);
            $flat = [];
            self::dot(self::literals($array, new ConstExprEvaluator()), $group, $flat);

            return $flat;
        } catch (ParseError|ConstExprEvaluationException|UnexpectedValueException) {
            return null;
        }
    }

    /** The array literal of `<?php [declare(...);] return [...];`. */
    private static function returnedArray(string $content): Array_
    {
        // parse() returns null only when an error handler collects errors; the default throws.
        $statements = array_values(array_filter(
            (new ParserFactory())->createForHostVersion()->parse($content) ?? [],
            static fn (Stmt $statement): bool => ! $statement instanceof Nop,
        ));

        while (($statements[0] ?? null) instanceof Declare_ && $statements[0]->stmts === null) {
            array_shift($statements);
        }

        $return = $statements[0] ?? null;

        if (count($statements) > 1 || ! $return instanceof Return_ || ! $return->expr instanceof Array_) {
            throw new UnexpectedValueException('Not `return <array literal>;`.');
        }

        return $return->expr;
    }

    /**
     * An array literal as a PHP array, letting PHP resolve implicit and numeric-string keys and
     * repeated keys; leaves are evaluated without any user function or constant.
     *
     * @return array<int|string, mixed>
     * @throws ConstExprEvaluationException|UnexpectedValueException when anything is not a literal
     */
    private static function literals(Array_ $array, ConstExprEvaluator $evaluator): array
    {
        $out = [];

        foreach ($array->items as $item) {
            if ($item->byRef || $item->unpack) {
                throw new UnexpectedValueException('By-reference and spread items are not literals.');
            }

            if ($item->value instanceof Array_) {
                $value = self::literals($item->value, $evaluator);
            } else {
                $value = $evaluator->evaluateSilently($item->value);

                if ($value !== null && ! is_scalar($value)) {
                    throw new UnexpectedValueException('A leaf must be a scalar or null.');
                }
            }

            if ($item->key === null) {
                $out[] = $value;

                continue;
            }

            $key = $evaluator->evaluateSilently($item->key);

            if (! is_int($key) && ! is_string($key)) {
                throw new UnexpectedValueException('An array key must be an integer or a string.');
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<int|string, mixed> $literals
     * @param array<string, string> $flat
     * @throws UnexpectedValueException on a leaf that cannot be encoded or a repeated flattened key
     */
    private static function dot(array $literals, string $prefix, array &$flat): void
    {
        foreach ($literals as $key => $value) {
            $name = $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                self::dot($value, $name, $flat);

                continue;
            }

            $encoded = self::encode($value);

            if ($encoded === null || isset($flat[$name])) {
                throw new UnexpectedValueException('Cannot encode ' . $name . ' or it repeats another key.');
            }

            $flat[$name] = $encoded;
        }
    }
}
