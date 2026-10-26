<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Laravel\Rules\TranslationRule;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\NonEdgeInputs;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The language scopes (`trans@1`, `lang@1`, `lang:uncomparable@1`) against
 * {@see TranslationRule}: after a change under a language root, a test file's digest moves
 * only when the rule selects it, and when it compares by key, exactly then.
 */
final class NonEdgeInputsTranslationsTest extends TestCase
{
    private const EN_JSON = "{\n    \"Hello\": \"Hello\",\n    \"Unused\": \"Unused\"\n}\n";

    private const EN_MESSAGES = "<?php\n\nreturn [\n    'welcome' => 'Welcome',\n    'nested' => ['title' => 'Title', 'body' => 'Body'],\n    'unused' => 'Nobody',\n];\n";

    private const ES_MESSAGES = "<?php\n\nreturn ['welcome' => 'Bienvenido'];\n";

    private const TESTS = [
        'tests/JsonTest.php',
        'tests/WelcomeTest.php',
        'tests/SubtreeTest.php',
        'tests/LeafTest.php',
        'tests/StarTest.php',
        'tests/PlainTest.php',
        'tests/OwnTest.php',
    ];

    private GitRepo $repo;

    private Graph $graph;

    private string $base;

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
        $this->repo->write('.gitignore', "lang/ignored.json\n");
        $this->repo->write('lang/en.json', self::EN_JSON);
        $this->repo->write('lang/es.json', "{\"Hello\": \"Hola\"}\n");
        $this->repo->write('lang/en/messages.php', self::EN_MESSAGES);
        $this->repo->write('lang/es/messages.php', self::ES_MESSAGES);
        $this->repo->write('lang/en/own.php', "<?php\n\nreturn ['mine' => 'Mine'];\n");
        $this->repo->write('lang/vendor/nova/es.json', "{\"Save\": \"Guardar\"}\n");
        $this->repo->write('lang/README.md', "Translations.\n");
        $this->repo->write('src/A.php', "<?php\nfinal class A {}\n");

        foreach (self::TESTS as $test) {
            $this->repo->write($test, "<?php\n");
        }

        $this->base = $this->repo->commitAll('initial');

        $this->graph = new Graph($this->repo->root);
        $this->graph->unionEdges(['tests/PlainTest.php' => ['src/A.php'], 'tests/OwnTest.php' => ['lang/en/own.php']]);
        $this->graph->markKnownTestFiles(self::TESTS);
        $this->graph->unionTestTranslations([
            'tests/JsonTest.php' => ['en:Hello'],
            'tests/WelcomeTest.php' => ['es:messages.welcome', 'en:messages.welcome'],
            'tests/SubtreeTest.php' => ['en:messages.nested'],
            'tests/LeafTest.php' => ['en:messages.nested.title', 'en:messages.nested.title.deeper'],
            'tests/StarTest.php' => ['*', 'en:Hello'],
            'tests/OwnTest.php' => ['en:own.mine'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    /** @return iterable<string, array{array<string, ?string>, list<string>}> writes (null: delete), what the rule selects */
    public static function keyChanges(): iterable
    {
        yield 'JSON key' => [['lang/en.json' => str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON)], ['tests/JsonTest.php', 'tests/StarTest.php']];
        yield 'JSON key added' => [['lang/en.json' => '{"Hello": "Hello", "Unused": "Unused", "New": "New"}'], ['tests/StarTest.php']];
        yield 'JSON key of another locale' => [['lang/es.json' => "{\"Hello\": \"Buenas\"}\n"], ['tests/StarTest.php']];
        yield 'PHP key' => [['lang/en/messages.php' => str_replace("'Welcome'", "'Welcome!'", self::EN_MESSAGES)], ['tests/StarTest.php', 'tests/WelcomeTest.php']];
        yield 'PHP key, the fallback locale' => [['lang/es/messages.php' => str_replace('Bienvenido', 'Bienvenida', self::ES_MESSAGES)], ['tests/StarTest.php', 'tests/WelcomeTest.php']];
        yield 'leaf of a subtree' => [['lang/en/messages.php' => str_replace("'Title'", "'Heading'", self::EN_MESSAGES)], ['tests/LeafTest.php', 'tests/StarTest.php', 'tests/SubtreeTest.php']];
        yield 'sibling leaf' => [['lang/en/messages.php' => str_replace("'Body'", "'Text'", self::EN_MESSAGES)], ['tests/StarTest.php', 'tests/SubtreeTest.php']];
        yield 'leaf turned subtree' => [['lang/en/messages.php' => str_replace("'Title'", "['deeper' => 'Deep']", self::EN_MESSAGES)], ['tests/LeafTest.php', 'tests/StarTest.php', 'tests/SubtreeTest.php']];
        yield 'unused key' => [['lang/en/messages.php' => str_replace("'Nobody'", "'Somebody'", self::EN_MESSAGES)], ['tests/StarTest.php']];
        yield 'formatting, order, comments' => [['lang/en.json' => '{"Unused":"Unused","Hello":"Hello"}', 'lang/en/messages.php' => "<?php\n// c\nreturn ['unused' => 'Nobody', 'nested' => ['body' => 'Body', 'title' => 'Title'], 'welcome' => \"Welcome\"];\n"], []];
        yield 'a key of a file a test executes' => [['lang/en/own.php' => "<?php\n\nreturn ['mine' => 'Ours'];\n"], ['tests/OwnTest.php', 'tests/StarTest.php']];
        yield 'two files at once' => [['lang/en.json' => str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON), 'lang/es/messages.php' => str_replace('Bienvenido', 'Bienvenida', self::ES_MESSAGES)], ['tests/JsonTest.php', 'tests/StarTest.php', 'tests/WelcomeTest.php']];
        yield 'an ignored file' => [['lang/ignored.json' => '{"Hello": "x"}'], []];
    }

    /** @param array<string, ?string> $writes @param list<string> $selected */
    #[DataProvider('keyChanges')]
    #[Group('AC-1')]
    public function test_a_key_change_moves_exactly_the_digests_of_the_tests_the_rule_selects(array $writes, array $selected): void
    {
        [$moved, $bySelection] = $this->change($writes);

        self::assertSame($selected, $bySelection);
        self::assertSame($selected, $moved, 'the digest moves if and only if the rule selects the test');
    }

    /**
     * @return iterable<string, array{array<string, ?string>, list<string>, bool}> writes (null: delete),
     *         the digests that move, whether the broken version is the base
     */
    public static function uncomparableChanges(): iterable
    {
        $carriers = ['tests/JsonTest.php', 'tests/LeafTest.php', 'tests/OwnTest.php', 'tests/PlainTest.php', 'tests/StarTest.php', 'tests/SubtreeTest.php', 'tests/WelcomeTest.php'];

        yield 'vendor' => [['lang/vendor/nova/es.json' => "{\"Save\": \"Guardar ya\"}\n"], $carriers, false];
        yield 'not a translation file' => [['lang/README.md' => "Changed.\n"], $carriers, false];
        yield 'broken JSON' => [['lang/en.json' => '{"Hello": '], $carriers, false];
        yield 'broken JSON fixed' => [['lang/es.json' => '{"Hello": '], $carriers, true];
        yield 'not a literal array' => [['lang/es/messages.php' => "<?php\n\nreturn ['welcome' => ucfirst('b')];\n"], $carriers, false];
        // A comparable file created or deleted: only who read it moves, a part of every test.
        yield 'new file' => [['lang/fr.json' => "{\"Hello\": \"Bonjour\"}\n"], ['tests/StarTest.php'], false];
        yield 'new group' => [['lang/en/extra.php' => "<?php\n\nreturn ['messages' => 'x'];\n"], ['tests/StarTest.php'], false];
        yield 'deleted file' => [['lang/es/messages.php' => null], ['tests/StarTest.php', 'tests/WelcomeTest.php'], false];
        yield 'deleted vendor file' => [['lang/vendor/nova/es.json' => null], $carriers, false];
    }

    /**
     * @param array<string, ?string> $writes
     * @param list<string> $moving
     */
    #[DataProvider('uncomparableChanges')]
    public function test_what_the_rule_cannot_compare_selects_every_test_whose_digest_may_move(array $writes, array $moving, bool $brokenBase): void
    {
        if ($brokenBase) {
            // The broken version is the base: fixing it cannot be compared either.
            $this->write($writes);
            $this->base = $this->repo->commitAll('broken');
            $writes = ['lang/es.json' => "{\"Hello\": \"Hola\"}\n"];
        }

        [$moved, $bySelection] = $this->change($writes);

        self::assertSame(self::sorted(self::TESTS), $bySelection, 'every test');
        self::assertSame($moving, $moved, 'a part of the selection');
    }

    public function test_while_no_key_is_recorded_every_language_file_is_every_test_s_input(): void
    {
        $this->graph = new Graph($this->repo->root);
        $this->graph->unionEdges(['tests/PlainTest.php' => ['src/A.php']]);
        $before = $this->digests();

        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));

        $after = $this->digests();

        foreach (self::TESTS as $test) {
            self::assertNotSame($before[$test], $after[$test], $test);
        }
    }

    /**
     * An entry without a locale (a hand-edited graph) reaches nothing, and one whose locale
     * has no file reaches nothing until that file exists — when the rule, which cannot
     * compare a new file, selects every test.
     */
    public function test_entries_that_reach_no_file_are_no_input_until_their_file_exists(): void
    {
        $this->graph->unionTestTranslations(['tests/PlainTest.php' => ['Hello', 'fr:Hello']]);
        $before = $this->digests();

        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));
        $edited = $this->digests();

        self::assertSame($before['tests/PlainTest.php'], $edited['tests/PlainTest.php']);

        [$moved, $bySelection] = $this->change(['lang/fr.json' => "{\"Hello\": \"Bonjour\"}\n"]);

        self::assertContains('tests/PlainTest.php', $moved);
        self::assertSame(self::sorted(self::TESTS), $bySelection);
    }

    /** A tracked file that matches an ignore pattern is no input: no diff ever reports it. */
    public function test_a_tracked_language_file_git_ignores_is_no_input(): void
    {
        $this->repo->write('lang/ignored.json', "{\"Hello\": \"x\"}\n");
        $this->repo->git('add', '-f', 'lang/ignored.json');
        $this->base = $this->repo->commitAll('tracked though ignored');

        [$moved, $bySelection] = $this->change(['lang/ignored.json' => "{\"Hello\": \"y\"}\n"]);

        self::assertSame([], $moved);
        self::assertSame([], $bySelection);
    }

    public function test_without_the_rule_no_language_scope_exists(): void
    {
        $before = $this->digests([]);

        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));
        $this->repo->write('lang/vendor/nova/es.json', "{\"Save\": \"Guardar ya\"}\n");

        self::assertSame($before, $this->digests([]));
    }

    public function test_with_static_declaration_edges_a_language_file_never_falls_to_the_residue(): void
    {
        $writes = ['lang/en/messages.php' => str_replace("'Nobody'", "'Somebody'", self::EN_MESSAGES)];
        $rule = ['translation' => new TranslationRule()];
        $before = $this->digests($rule, true);
        $beforeWithout = $this->digests([], true);

        $this->write($writes);

        self::assertSame($before['tests/PlainTest.php'], $this->digests($rule, true)['tests/PlainTest.php']);
        self::assertNotSame($beforeWithout['tests/PlainTest.php'], $this->digests([], true)['tests/PlainTest.php'], 'the residue, without the rule');
    }

    public function test_a_language_file_that_changed_after_it_was_read_refuses_the_stamp(): void
    {
        $inputs = $this->inputs();
        self::assertNotNull($inputs->stampFor('tests/JsonTest.php'));

        $inputs = $this->inputs();
        $inputs->digestFor('tests/JsonTest.php');
        $inputs->digestFor('tests/StarTest.php');
        $inputs->digestFor('tests/PlainTest.php');

        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hello again, longer"', self::EN_JSON));

        self::assertNull($inputs->stampFor('tests/JsonTest.php'), 'trans@1');
        self::assertNull($inputs->stampFor('tests/StarTest.php'), 'lang@1');

        $inputs = $this->inputs();
        $inputs->digestFor('tests/PlainTest.php');
        $this->repo->write('lang/vendor/nova/es.json', "{\"Save\": \"Guardar, more than before\"}\n");

        self::assertNull($inputs->stampFor('tests/PlainTest.php'), 'lang:uncomparable@1');
    }

    public function test_the_digest_is_stable_across_refresh_and_instances(): void
    {
        $inputs = $this->inputs();
        $digest = $inputs->digestFor('tests/LeafTest.php');
        $inputs->refresh();

        self::assertSame($digest, $inputs->digestFor('tests/LeafTest.php'));
        self::assertSame($digest, $this->inputs()->digestFor('tests/LeafTest.php'));
        self::assertNotSame($digest, $this->inputs()->digestFor('tests/SubtreeTest.php'));
    }

    public function test_covers_every_path_under_a_language_root_while_the_rule_runs(): void
    {
        self::assertTrue($this->inputs()->covers('lang/de.json'));
        self::assertTrue($this->inputs()->covers('resources/lang/de/x.php'));
        self::assertTrue($this->inputs()->covers('lang/vendor/x/de/y.php'));
        self::assertFalse($this->inputs([])->covers('lang/de.json'));
        self::assertFalse($this->inputs()->covers('notes/de.json'));
    }

    /**
     * Digests before and after `$writes`, and what the rule selects for them.
     *
     * @param array<string, ?string> $writes
     * @return array{list<string>, list<string>} test files whose digest moved, test files selected
     */
    private function change(array $writes): array
    {
        $before = $this->digests();
        $this->write($writes);
        $after = $this->digests();

        $moved = [];

        foreach (self::TESTS as $test) {
            if ($before[$test] !== $after[$test]) {
                $moved[] = $test;
            }
        }

        $selection = new Selection();
        $context = new Context(
            $this->graph,
            $this->repo->root,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            array_values(array_filter(array_keys($writes), static fn (string $path): bool => $path !== 'lang/ignored.json')),
            $selection,
            $this->base,
        );
        (new TranslationRule())->apply($context);

        return [self::sorted($moved), self::sorted($selection->testFiles())];
    }

    /** @param array<string, ?string> $writes */
    private function write(array $writes): void
    {
        foreach ($writes as $path => $content) {
            if ($content === null) {
                $this->repo->delete($path);
            } else {
                $this->repo->write($path, $content);
            }
        }
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private static function sorted(array $files): array
    {
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param array{translation?: TranslationRule} $rules
     * @return array<string, ?string>
     */
    private function digests(?array $rules = null, bool $staticDeclarationEdges = false): array
    {
        $inputs = $this->inputs($rules, $staticDeclarationEdges);
        $out = [];

        foreach (self::TESTS as $test) {
            $out[$test] = $inputs->digestFor($test);
        }

        return $out;
    }

    /** @param array{translation?: TranslationRule}|null $rules */
    private function inputs(?array $rules = null, bool $staticDeclarationEdges = false): NonEdgeInputs
    {
        return new NonEdgeInputs(
            $this->graph,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            $this->repo->root,
            new FileHashes($this->repo->root),
            new Git($this->repo->root),
            $rules ?? ['translation' => new TranslationRule()],
            $staticDeclarationEdges,
        );
    }
}
