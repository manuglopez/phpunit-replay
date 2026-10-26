<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\LangFiles;
use Manuglopez\Replay\Laravel\LangPath;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Language files read without running them: path classification, flattening a translation
 * file to `key => canonical value`, and the keys that differ between two versions.
 */
final class LangFilesTest extends TestCase
{
    private static function php(string $body): string
    {
        return "<?php\n\nreturn " . $body . ";\n";
    }

    private static function messages(): LangPath
    {
        return new LangPath(LangPath::PHP, 'es', 'messages');
    }

    private static function json(): LangPath
    {
        return new LangPath(LangPath::JSON, 'es', null);
    }

    // ---------------------------------------------------------------- I2-a: classify

    /** @return array<string, array{string, string, ?string, ?string}> */
    public static function classified(): array
    {
        return [
            'json in lang' => ['lang/es.json', LangPath::JSON, 'es', null],
            'json in resources/lang' => ['resources/lang/en.json', LangPath::JSON, 'en', null],
            'json locale with a dot' => ['lang/pt.BR.json', LangPath::JSON, 'pt.BR', null],
            'php group in lang' => ['lang/es/messages.php', LangPath::PHP, 'es', 'messages'],
            'php group in resources/lang' => ['resources/lang/en/validation.php', LangPath::PHP, 'en', 'validation'],
            'php group in a subdirectory' => ['lang/es/admin/users.php', LangPath::PHP, 'es', 'admin/users'],
            'php group two levels down' => ['resources/lang/es/a/b.php', LangPath::PHP, 'es', 'a/b'],
            'php group two dirs deep' => ['lang/es/a/b/c.php', LangPath::PHP, 'es', 'a/b/c'],
            'vendor json' => ['lang/vendor/nova/es.json', LangPath::VENDOR, null, null],
            'vendor php' => ['resources/lang/vendor/pkg/es/auth.php', LangPath::VENDOR, null, null],
            'vendor anything' => ['lang/vendor/x', LangPath::VENDOR, null, null],
            'readme' => ['lang/README.md', LangPath::OTHER, null, null],
            'text file in a locale' => ['lang/es/x.txt', LangPath::OTHER, null, null],
            'json backup' => ['lang/en.json.bak', LangPath::OTHER, null, null],
            'json without a locale' => ['lang/.json', LangPath::OTHER, null, null],
            'json inside a locale directory' => ['lang/es/messages.json', LangPath::OTHER, null, null],
            'php without a group name' => ['lang/es/.php', LangPath::OTHER, null, null],
            'php group with an empty last segment' => ['lang/es/a/.php', LangPath::OTHER, null, null],
            'php directly under the root' => ['lang/messages.php', LangPath::OTHER, null, null],
            'a file called vendor' => ['lang/vendor', LangPath::OTHER, null, null],
            'a file called vendor.php' => ['lang/vendor.php', LangPath::OTHER, null, null],
            'a vendor-ish json locale' => ['lang/vendor.json', LangPath::JSON, 'vendor', null],
            'empty segment' => ['lang//es.json', LangPath::OTHER, null, null],
            'empty segment deeper' => ['lang/es//a.php', LangPath::OTHER, null, null],
            'deep php under resources' => ['resources/lang/es/a.php', LangPath::PHP, 'es', 'a'],
        ];
    }

    #[Group('I2-a')]
    #[DataProvider('classified')]
    public function test_classify_shapes(string $rel, string $kind, ?string $locale, ?string $group): void
    {
        $path = LangFiles::classify($rel);

        self::assertNotNull($path, $rel);
        self::assertSame($kind, $path->kind);
        self::assertSame($locale, $path->locale);
        self::assertSame($group, $path->group);
    }

    /** @return array<string, array{string}> */
    public static function outside(): array
    {
        return [
            'app' => ['app/Models/User.php'],
            'the root itself' => ['lang'],
            'the root with a trailing slash' => ['lang/'],
            'resources root' => ['resources/lang'],
            'resources root with a slash' => ['resources/lang/'],
            'a sibling directory sharing the prefix' => ['language/es.json'],
            'a prefix of the name' => ['langs/es.json'],
            'nested lang' => ['app/lang/es.json'],
            'resources, not lang' => ['resources/views/es.json'],
            'resources/langs' => ['resources/langs/es.json'],
            'empty' => [''],
            'tests' => ['tests/Feature/lang/es.json'],
        ];
    }

    #[Group('I2-a')]
    #[DataProvider('outside')]
    public function test_classify_is_null_outside_the_roots(string $rel): void
    {
        self::assertNull(LangFiles::classify($rel));
    }

    #[Group('I2-a')]
    public function test_the_roots_are_lang_and_resources_lang(): void
    {
        self::assertSame(['lang', 'resources/lang'], LangFiles::ROOTS);
        self::assertSame(['json', 'php', 'vendor', 'other'], [LangPath::JSON, LangPath::PHP, LangPath::VENDOR, LangPath::OTHER]);
    }

    // ---------------------------------------------------------------- I2-b: flatten JSON

    #[Group('I2-b')]
    public function test_flatten_json_keeps_the_key_literal_and_encodes_the_value(): void
    {
        $flat = LangFiles::flatten('{"Welcome, :name.": "Hola, :name.", "a.b": "x", "n": 1, "t": true, "z": null, "o": {"k": [1, 2]}}', self::json());

        self::assertSame([
            'Welcome, :name.' => '"Hola, :name."',
            'a.b' => '"x"',
            'n' => '1',
            't' => 'true',
            'z' => 'null',
            'o' => '{"k":[1,2]}',
        ], $flat);
    }

    #[Group('I2-b')]
    public function test_flatten_json_keys_that_look_like_integers_stay_strings(): void
    {
        $flat = LangFiles::flatten('{"404": "no", "a": "b"}', self::json());

        self::assertSame(['404', 'a'], array_map(strval(...), array_keys($flat ?? [])));
    }

    #[Group('I2-b')]
    public function test_flatten_json_does_not_escape_slashes(): void
    {
        self::assertSame(['u' => '"a/b"'], LangFiles::flatten('{"u": "a\/b"}', self::json()));
    }

    #[Group('I2-b')]
    public function test_flatten_json_empty_object_is_an_empty_map(): void
    {
        self::assertSame([], LangFiles::flatten('{}', self::json()));
        self::assertSame([], LangFiles::flatten("  {\n}\n", self::json()));
    }

    /** @return array<string, array{string}> */
    public static function notJsonTranslations(): array
    {
        return [
            'invalid' => ['{"a": '],
            'truncated' => ['{"a": "b"'],
            'empty' => [''],
            'trailing comma' => ['{"a": "b",}'],
            'a list' => ['["a", "b"]'],
            'an empty list' => ['[]'],
            'a string' => ['"hola"'],
            'a number' => ['12'],
            'null' => ['null'],
            'a bom' => ["\xEF\xBB\xBF{\"a\": \"b\"}"],
        ];
    }

    #[Group('I2-b')]
    #[DataProvider('notJsonTranslations')]
    public function test_flatten_json_that_is_not_an_object_is_null(string $content): void
    {
        self::assertNull(LangFiles::flatten($content, self::json()));
    }

    // ---------------------------------------------------------------- I2-b: flatten PHP

    #[Group('I2-b')]
    public function test_flatten_php_prefixes_the_group_and_dots_the_nesting(): void
    {
        $flat = LangFiles::flatten(self::php("[
            'welcome' => 'Hola',
            'user' => ['name' => 'Nombre', 'deep' => ['x' => 'y']],
            'count' => 3,
            'ratio' => 1.5,
            'on' => true,
            'off' => false,
            'nothing' => null,
        ]"), self::messages());

        self::assertSame([
            'messages.welcome' => '"Hola"',
            'messages.user.name' => '"Nombre"',
            'messages.user.deep.x' => '"y"',
            'messages.count' => '3',
            'messages.ratio' => '1.5',
            'messages.on' => 'true',
            'messages.off' => 'false',
            'messages.nothing' => 'null',
        ], $flat);
    }

    #[Group('I2-b')]
    public function test_flatten_php_group_with_a_subdirectory_keeps_the_slash(): void
    {
        $flat = LangFiles::flatten(self::php("['title' => 'T']"), new LangPath(LangPath::PHP, 'es', 'admin/users'));

        self::assertSame(['admin/users.title' => '"T"'], $flat);
    }

    #[Group('I2-b')]
    public function test_flatten_php_accepts_the_long_array_syntax_and_declare(): void
    {
        $flat = LangFiles::flatten("<?php\n\ndeclare(strict_types=1);\n\nreturn array('a' => array('b' => 'c'));\n", self::messages());

        self::assertSame(['messages.a.b' => '"c"'], $flat);
    }

    #[Group('I2-b')]
    public function test_flatten_php_an_empty_nested_array_is_a_leaf_and_an_empty_file_has_none(): void
    {
        self::assertSame(['messages.empty' => '[]', 'messages.a' => '"b"'], LangFiles::flatten(self::php("['empty' => [], 'a' => 'b']"), self::messages()));
        self::assertSame([], LangFiles::flatten(self::php('[]'), self::messages()));
        self::assertSame(['messages.n.e' => '[]'], LangFiles::flatten(self::php("['n' => ['e' => array()]]"), self::messages()));
    }

    #[Group('I2-b')]
    public function test_flatten_php_integer_and_missing_keys_follow_php_semantics(): void
    {
        $flat = LangFiles::flatten(self::php("['first', 'second', 5 => 'five', 'six', '7' => 'seven', 'eight', 'k' => ['x', 'y']]"), self::messages());

        self::assertSame([
            'messages.0' => '"first"',
            'messages.1' => '"second"',
            'messages.5' => '"five"',
            'messages.6' => '"six"',
            'messages.7' => '"seven"',
            'messages.8' => '"eight"',
            'messages.k.0' => '"x"',
            'messages.k.1' => '"y"',
        ], $flat);
    }

    #[Group('I2-b')]
    public function test_flatten_php_the_last_duplicate_key_wins_like_php(): void
    {
        self::assertSame(['messages.a' => '"two"'], LangFiles::flatten(self::php("['a' => 'one', 'a' => 'two']"), self::messages()));
    }

    #[Group('I2-b')]
    public function test_flatten_php_evaluates_literal_concatenation_and_constant_expressions(): void
    {
        $flat = LangFiles::flatten(self::php("[
            'a' => 'Hola, ' . 'mundo' . '!',
            'b' => 1 + 2,
            'c' => -4,
            'd' => PHP_INT_SIZE > 0 ? 'x' : 'y',
        ]"), self::messages());

        self::assertNull($flat, 'PHP_INT_SIZE is a constant: not literal');

        $flat = LangFiles::flatten(self::php("[
            'a' => 'Hola, ' . 'mundo' . '!',
            'b' => 1 + 2,
            'c' => -4,
            'd' => true ? 'x' : 'y',
            \"e\" => <<<'TXT'
            line one
            TXT,
            'f' => \"double \\\" quoted \\n\",
        ]"), self::messages());

        self::assertSame([
            'messages.a' => '"Hola, mundo!"',
            'messages.b' => '3',
            'messages.c' => '-4',
            'messages.d' => '"x"',
            'messages.e' => '"line one"',
            'messages.f' => '"double \" quoted \n"',
        ], $flat);
    }

    #[Group('I2-b')]
    public function test_flatten_php_literal_keys_may_be_concatenated_too(): void
    {
        self::assertSame(['messages.ab' => '"v"'], LangFiles::flatten(self::php("['a' . 'b' => 'v']"), self::messages()));
    }

    /** @return array<string, array{string}> */
    public static function nonLiteralPhp(): array
    {
        return [
            'function call' => [self::php("['a' => strtoupper('x')]")],
            'translation helper call' => [self::php("['a' => __('other.key')]")],
            'constant' => [self::php("['a' => PHP_EOL]")],
            'class constant' => [self::php("['a' => Foo::BAR]")],
            'magic constant' => [self::php("['a' => __DIR__]")],
            'variable' => [self::php("['a' => \$x]")],
            'variable key' => [self::php("[\$k => 'x']")],
            'function call as key' => [self::php("[strtolower('A') => 'x']")],
            'interpolated string' => [self::php("['a' => \"hello \$name\"]")],
            'interpolated heredoc' => ["<?php\n\nreturn ['a' => <<<TXT\nhello \$name\nTXT];\n"],
            'static method call' => [self::php("['a' => Str::title('x')]")],
            'new' => [self::php("['a' => new Foo()]")],
            'closure' => [self::php("['a' => fn () => 1]")],
            'nested non literal' => [self::php("['a' => ['b' => ['c' => foo()]]]")],
            'spread' => [self::php("[...['a' => 'b']]")],
            'by reference' => [self::php("['a' => &\$x]")],
            'array as key' => [self::php("[[1] => 'x']")],
            'float key' => [self::php("[1.5 => 'x']")],
            'bool key' => [self::php("[true => 'x']")],
            'null key' => [self::php("[null => 'x']")],
            'array result from an expression' => [self::php("['a' => ['x'] + ['y']]")],
            'division by zero' => [self::php("['a' => 1 / 0]")],
            'array to string' => [self::php("['a' => 'x' . []]")],
            'infinite float' => [self::php("['a' => 1e999]")],
            'include' => ["<?php\n\nreturn include __DIR__ . '/other.php';\n"],
            'include statement' => ["<?php\n\ninclude 'x.php';\nreturn ['a' => 'b'];\n"],
            'require_once' => ["<?php\n\nrequire_once 'x.php';\nreturn ['a' => 'b'];\n"],
            'assignment before return' => ["<?php\n\n\$x = ['a' => 'b'];\nreturn \$x;\n"],
            'return a variable' => ["<?php\n\nreturn \$messages;\n"],
            'return a call' => ["<?php\n\nreturn array_merge(['a' => 'b'], []);\n"],
            'return a string' => ["<?php\n\nreturn 'x';\n"],
            'return nothing' => ["<?php\n\nreturn;\n"],
            'no return' => ["<?php\n\n\$x = 1;\n"],
            'empty file' => [''],
            'only open tag' => ["<?php\n"],
            'declare with a block' => ["<?php\n\ndeclare(ticks=1) { return ['a' => 'b']; }\n"],
            'namespace' => ["<?php\n\nnamespace App;\n\nreturn ['a' => 'b'];\n"],
            'code after the return' => ["<?php\n\nreturn ['a' => 'b'];\nfoo();\n"],
            'two returns' => ["<?php\n\nreturn ['a' => 'b'];\nreturn ['c' => 'd'];\n"],
            'html before the tag' => ["hello\n<?php\n\nreturn ['a' => 'b'];\n"],
            'html after the closing tag' => ["<?php\n\nreturn ['a' => 'b'];\n?>\ntrailing\n"],
            'syntax error' => ["<?php\n\nreturn ['a' => ;\n"],
            'unterminated array' => ["<?php\n\nreturn ['a' => 'b'\n"],
            'garbage' => ['not php at all'],
            'a flattened-key collision' => [self::php("['a.b' => 'x', 'a' => ['b' => 'y']]")],
        ];
    }

    #[Group('I2-b')]
    #[DataProvider('nonLiteralPhp')]
    public function test_flatten_php_that_is_not_a_literal_array_is_null(string $content): void
    {
        self::assertNull(LangFiles::flatten($content, self::messages()));
    }

    #[Group('I2-b')]
    public function test_flatten_php_collision_is_null_even_when_values_are_equal(): void
    {
        self::assertNull(LangFiles::flatten(self::php("['a.b' => 'x', 'a' => ['b' => 'x']]"), self::messages()));
        self::assertNull(LangFiles::flatten(self::php("['a' => ['b' => 'x'], 'a.b' => 'x']"), self::messages()));
    }

    #[Group('I2-b')]
    public function test_flatten_php_comments_and_layout_do_not_matter(): void
    {
        $plain = LangFiles::flatten(self::php("['a' => 'b', 'c' => ['d' => 'e']]"), self::messages());
        $dressed = LangFiles::flatten("<?php\n// a comment\n/* block */\nreturn [ # hash\n  'a'   =>   'b' , /** doc */\n  'c' => [\n 'd' => 'e', // tail\n ],\n];\n", self::messages());

        self::assertSame($plain, $dressed);
        self::assertSame(['messages.a' => '"b"', 'messages.c.d' => '"e"'], $dressed);
    }

    #[Group('I2-b')]
    public function test_flatten_only_json_and_php_languages_files(): void
    {
        self::assertNull(LangFiles::flatten('{"a": "b"}', new LangPath(LangPath::VENDOR, null, null)));
        self::assertNull(LangFiles::flatten('{"a": "b"}', new LangPath(LangPath::OTHER, null, null)));
        self::assertNull(LangFiles::flatten("<?php\nreturn ['a' => 'b'];\n", new LangPath(LangPath::OTHER, null, null)));
    }

    #[Group('I2-b')]
    public function test_flatten_a_php_path_without_a_group_is_not_comparable(): void
    {
        self::assertNull(LangFiles::flatten(self::php("['a' => 'b']"), new LangPath(LangPath::PHP, 'es', null)));
    }

    // ---------------------------------------------------------------- I2-b: changedKeys

    #[Group('I2-b')]
    public function test_changed_keys_a_changed_value(): void
    {
        self::assertSame(
            ['messages.b'],
            LangFiles::changedKeys(self::php("['a' => 'x', 'b' => 'y']"), self::php("['a' => 'x', 'b' => 'z']"), self::messages()),
        );
    }

    #[Group('I2-b')]
    public function test_changed_keys_an_added_key(): void
    {
        self::assertSame(
            ['messages.c'],
            LangFiles::changedKeys(self::php("['a' => 'x']"), self::php("['a' => 'x', 'c' => 'n']"), self::messages()),
        );
    }

    #[Group('I2-b')]
    public function test_changed_keys_a_deleted_key(): void
    {
        self::assertSame(
            ['messages.a'],
            LangFiles::changedKeys(self::php("['a' => 'x', 'b' => 'y']"), self::php("['b' => 'y']"), self::messages()),
        );
    }

    #[Group('I2-b')]
    public function test_changed_keys_nested_keys_are_reported_by_their_full_path(): void
    {
        self::assertSame(
            ['messages.user.deep.x'],
            LangFiles::changedKeys(
                self::php("['user' => ['name' => 'n', 'deep' => ['x' => 'old']]]"),
                self::php("['user' => ['name' => 'n', 'deep' => ['x' => 'new']]]"),
                self::messages(),
            ),
        );
    }

    #[Group('I2-b')]
    public function test_changed_keys_an_array_becoming_empty_or_filled(): void
    {
        self::assertSame(
            ['messages.list.b', 'messages.list.c'],
            LangFiles::changedKeys(self::php("['list' => ['a' => '1', 'b' => '2']]"), self::php("['list' => ['a' => '1', 'b' => '3', 'c' => '4']]"), self::messages()),
        );
        self::assertSame(
            ['messages.list', 'messages.list.a'],
            LangFiles::changedKeys(self::php("['list' => ['a' => '1']]"), self::php("['list' => []]"), self::messages()),
        );
        self::assertSame(
            ['messages.list', 'messages.list.a'],
            LangFiles::changedKeys(self::php("['list' => []]"), self::php("['list' => ['a' => '1']]"), self::messages()),
        );
        self::assertSame(
            [],
            LangFiles::changedKeys(self::php("['list' => []]"), self::php("['list' => array()]"), self::messages()),
        );
    }

    #[Group('I2-b')]
    public function test_changed_keys_a_type_change_counts(): void
    {
        self::assertSame(['messages.n'], LangFiles::changedKeys(self::php("['n' => 1]"), self::php("['n' => '1']"), self::messages()));
        self::assertSame(['messages.n'], LangFiles::changedKeys(self::php("['n' => 1]"), self::php("['n' => 1.0]"), self::messages()));
        self::assertSame(['messages.n'], LangFiles::changedKeys(self::php("['n' => false]"), self::php("['n' => null]"), self::messages()));
        self::assertSame(['messages.n'], LangFiles::changedKeys(self::php("['n' => 0]"), self::php("['n' => false]"), self::messages()));
        self::assertSame(['n'], LangFiles::changedKeys('{"n": 1}', '{"n": "1"}', self::json()));
        self::assertSame(['n'], LangFiles::changedKeys('{"n": "x"}', '{"n": ["x"]}', self::json()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_a_created_php_file_lists_its_keys_and_the_group(): void
    {
        self::assertSame(
            ['messages', 'messages.a', 'messages.b.c'],
            LangFiles::changedKeys(null, self::php("['a' => 'x', 'b' => ['c' => 'y']]"), self::messages()),
        );
    }

    #[Group('I2-b')]
    public function test_changed_keys_a_deleted_php_file_lists_its_keys_and_the_group(): void
    {
        self::assertSame(
            ['admin/users', 'admin/users.a'],
            LangFiles::changedKeys(self::php("['a' => 'x']"), null, new LangPath(LangPath::PHP, 'es', 'admin/users')),
        );
    }

    #[Group('I2-b')]
    public function test_changed_keys_a_created_or_deleted_empty_php_file_still_names_the_group(): void
    {
        self::assertSame(['messages'], LangFiles::changedKeys(null, self::php('[]'), self::messages()));
        self::assertSame(['messages'], LangFiles::changedKeys(self::php('[]'), null, self::messages()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_a_modified_php_file_does_not_name_the_group(): void
    {
        self::assertNotContains('messages', LangFiles::changedKeys(self::php("['a' => 'x']"), self::php("['a' => 'y']"), self::messages()) ?? []);
    }

    #[Group('I2-b')]
    public function test_changed_keys_a_created_or_deleted_json_file_has_no_group(): void
    {
        self::assertSame(['a', 'b'], LangFiles::changedKeys(null, '{"b": "2", "a": "1"}', self::json()));
        self::assertSame(['a'], LangFiles::changedKeys('{"a": "1"}', null, self::json()));
        self::assertSame([], LangFiles::changedKeys(null, '{}', self::json()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_both_sides_absent_is_empty(): void
    {
        self::assertSame([], LangFiles::changedKeys(null, null, self::messages()));
        self::assertSame([], LangFiles::changedKeys(null, null, self::json()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_reordering_and_comments_and_whitespace_change_nothing(): void
    {
        $old = self::php("['a' => 'x', 'b' => ['c' => 'y', 'd' => 'z']]");
        $new = "<?php\n\n// reordered and commented\nreturn [\n    'b' => [\n        'd'  =>  'z', /* x */\n        'c' => 'y',\n    ],\n    'a' => 'x',\n];\n";

        self::assertSame([], LangFiles::changedKeys($old, $new, self::messages()));
        self::assertSame([], LangFiles::changedKeys('{"a": "1", "b": "2"}', "{\n  \"b\": \"2\",\n  \"a\": \"1\"\n}\n", self::json()));
        self::assertSame([], LangFiles::changedKeys('{"u": "\u00e9"}', '{"u": "é"}', self::json()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_literal_concatenation_is_compared_by_value(): void
    {
        self::assertSame([], LangFiles::changedKeys(self::php("['a' => 'Hola mundo']"), self::php("['a' => 'Hola ' . 'mundo']"), self::messages()));
        self::assertSame(['messages.a'], LangFiles::changedKeys(self::php("['a' => 'Hola mundo']"), self::php("['a' => 'Hola ' . 'mundo!']"), self::messages()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_are_sorted_and_unique(): void
    {
        $changed = LangFiles::changedKeys(
            '{"b": "1", "a": "1", "10": "1", "9": "1"}',
            '{"b": "2", "a": "2", "10": "2", "c": "2"}',
            self::json(),
        );

        self::assertSame(['10', '9', 'a', 'b', 'c'], $changed);
    }

    #[Group('I2-b')]
    public function test_changed_keys_json_with_dots_and_colons_are_literal_keys(): void
    {
        self::assertSame(
            ['Order: :n. done', 'a.b'],
            LangFiles::changedKeys('{"a.b": "1", "Order: :n. done": "x", "k": "k"}', '{"a.b": "2", "Order: :n. done": "y", "k": "k"}', self::json()),
        );
    }

    /** @return array<string, array{?string, ?string}> */
    public static function oneSideNotComparable(): array
    {
        return [
            'old is not a literal' => [self::php("['a' => foo()]"), self::php("['a' => 'b']")],
            'new is not a literal' => [self::php("['a' => 'b']"), self::php("['a' => \$x]")],
            'old is broken' => ["<?php\nreturn [", self::php("['a' => 'b']")],
            'new is broken' => [self::php("['a' => 'b']"), 'garbage'],
            'both are broken' => ['a', 'b'],
            'created as non literal' => [null, self::php("['a' => foo()]")],
            'deleted as non literal' => [self::php("['a' => foo()]"), null],
            'new has an include' => [self::php("['a' => 'b']"), "<?php\nreturn include 'x.php';\n"],
        ];
    }

    #[Group('I2-b')]
    #[DataProvider('oneSideNotComparable')]
    public function test_changed_keys_php_is_null_when_a_present_side_does_not_flatten(?string $old, ?string $new): void
    {
        self::assertNull(LangFiles::changedKeys($old, $new, self::messages()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_json_is_null_when_a_present_side_does_not_flatten(): void
    {
        self::assertNull(LangFiles::changedKeys('{"a": ', '{"a": "b"}', self::json()));
        self::assertNull(LangFiles::changedKeys('{"a": "b"}', '["a"]', self::json()));
        self::assertNull(LangFiles::changedKeys(null, '"x"', self::json()));
        self::assertNull(LangFiles::changedKeys('{', null, self::json()));
    }

    #[Group('I2-b')]
    public function test_changed_keys_of_vendor_and_other_files_is_null_when_either_side_is_present(): void
    {
        $vendor = new LangPath(LangPath::VENDOR, null, null);
        $other = new LangPath(LangPath::OTHER, null, null);

        self::assertNull(LangFiles::changedKeys('{"a": "b"}', '{"a": "c"}', $vendor));
        self::assertNull(LangFiles::changedKeys(null, '{"a": "c"}', $vendor));
        self::assertNull(LangFiles::changedKeys('# readme', null, $other));
        self::assertNull(LangFiles::changedKeys('# readme', '# readme 2', $other));
    }

    // ---------------------------------------------------------------- I2-c: matches / subtree

    /** @return array<string, array{string, string, bool}> */
    public static function matching(): array
    {
        return [
            'equal' => ['a.b', 'a.b', true],
            'recorded is the parent' => ['a.b', 'a.b.c', true],
            'changed is the parent' => ['a.b.c', 'a.b', true],
            'group and a key in it' => ['messages', 'messages.welcome', true],
            'a key in it and the group' => ['messages.welcome', 'messages', true],
            'a grandchild' => ['a', 'a.b.c.d', true],
            'a sibling sharing a prefix' => ['a.b', 'a.bc', false],
            'a sibling sharing a prefix, reversed' => ['a.bc', 'a.b', false],
            'plain sibling' => ['a.b', 'a.c', false],
            'unrelated' => ['messages.welcome', 'validation.required', false],
            'prefix without a dot' => ['mess', 'messages.welcome', false],
            'json key with dots' => ['Welcome, :name.', 'Welcome, :name.', true],
            'json key and its dotted continuation' => ['Hello.', 'Hello. x', false],
            'json key with a colon, equal' => ['Total: :n', 'Total: :n', true],
            'json key with a colon, different' => ['Total: :n', 'Total: :m', false],
            'colon key vs dotted child' => ['Total:', 'Total:.x', true],
            'colon is not a separator' => ['a:b', 'a:b:c', false],
            'empty vs key' => ['', 'a', false],
            'empty vs empty' => ['', '', true],
            'trailing dot parent' => ['a.', 'a.b', false],
            'case matters' => ['A.b', 'a.b', false],
        ];
    }

    #[Group('I2-c')]
    #[DataProvider('matching')]
    public function test_matches(string $recorded, string $changed, bool $expected): void
    {
        self::assertSame($expected, LangFiles::matches($recorded, $changed));
    }

    #[Group('I2-c')]
    public function test_subtree_returns_the_key_itself_and_what_is_under_it_in_order(): void
    {
        $flat = [
            'messages.a' => '1',
            'messages.a.b' => '2',
            'messages.ab' => '3',
            'messages.a.c.d' => '4',
            'other.a' => '5',
            'messages' => '6',
        ];

        self::assertSame(
            ['messages.a' => '1', 'messages.a.b' => '2', 'messages.a.c.d' => '4'],
            LangFiles::subtree($flat, 'messages.a'),
        );
        self::assertSame(
            ['messages.a' => '1', 'messages.a.b' => '2', 'messages.ab' => '3', 'messages.a.c.d' => '4', 'messages' => '6'],
            LangFiles::subtree($flat, 'messages'),
        );
        self::assertSame([], LangFiles::subtree($flat, 'messages.z'));
        self::assertSame([], LangFiles::subtree($flat, 'mess'));
        self::assertSame([], LangFiles::subtree([], 'a'));
    }

    #[Group('I2-c')]
    public function test_subtree_json_keys_with_dots_and_colons(): void
    {
        $flat = ['Hello, :name.' => '"x"', 'Hello, :name. more' => '"y"', 'Total: :n' => '"z"', 'Total: :n.sub' => '"w"', 'Total: :nope' => '"v"'];

        self::assertSame(['Hello, :name.' => '"x"'], LangFiles::subtree($flat, 'Hello, :name.'));
        self::assertSame(['Total: :n' => '"z"', 'Total: :n.sub' => '"w"'], LangFiles::subtree($flat, 'Total: :n'));
    }

    #[Group('I2-c')]
    public function test_subtree_keys_that_look_like_integers(): void
    {
        $flat = LangFiles::flatten('{"404": "a", "404.x": "b", "4040": "c"}', self::json()) ?? [];

        self::assertSame(['404' => '"a"', '404.x' => '"b"'], LangFiles::subtree($flat, '404'));
    }

    // ---------------------------------------------------------------- I2-d: nothing is executed

    #[Group('I2-d')]
    public function test_a_php_file_with_side_effects_is_never_executed(): void
    {
        $dir = TempDir::make('lang-files');

        try {
            $marker = $dir . '/touched';
            $other = $dir . '/included.php';
            file_put_contents($other, "<?php\ntouch('{$dir}/included');\nreturn ['a' => 'b'];\n");

            $sources = [
                'touch call' => "<?php\n\ntouch('{$marker}');\n\nreturn ['a' => 'b'];\n",
                'touch in a value' => "<?php\n\nreturn ['a' => touch('{$marker}')];\n",
                'touch in a key' => "<?php\n\nreturn [touch('{$marker}') => 'b'];\n",
                'exit' => "<?php\n\nexit('executed');\n",
                'exit in a value' => "<?php\n\nreturn ['a' => exit('executed')];\n",
                'die before return' => "<?php\n\ndie('executed');\nreturn ['a' => 'b'];\n",
                'file_put_contents' => "<?php\n\nfile_put_contents('{$marker}', 'x');\nreturn [];\n",
                'include of a file that touches' => "<?php\n\nreturn include '{$other}';\n",
                'require of a file that touches' => "<?php\n\nrequire '{$other}';\nreturn ['a' => 'b'];\n",
                'eval' => "<?php\n\neval('touch(\"{$marker}\");');\nreturn [];\n",
                'a destructor' => "<?php\n\nclass D { function __destruct() { touch('{$marker}'); } }\nnew D();\nreturn ['a' => 'b'];\n",
            ];

            foreach ($sources as $name => $source) {
                self::assertNull(LangFiles::flatten($source, self::messages()), $name);
                self::assertNull(LangFiles::changedKeys($source, self::php("['a' => 'b']"), self::messages()), $name);
                self::assertNull(LangFiles::changedKeys(null, $source, self::messages()), $name);
            }

            self::assertFileDoesNotExist($marker);
            self::assertFileDoesNotExist($dir . '/included');
        } finally {
            TempDir::remove($dir);
        }
    }
}
