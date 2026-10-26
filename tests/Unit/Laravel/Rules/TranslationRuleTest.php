<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel\Rules;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Laravel\Rules\TranslationRule;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Selection;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A changed language file selects the test files that resolved one of its changed keys in its
 * locale (and the ones whose translations could not be traced), compared with the version at
 * the base; what cannot be compared selects every test, for that file only.
 */
final class TranslationRuleTest extends TestCase
{
    private const EN_JSON = "{\n    \"Hello\": \"Hello\",\n    \"Unused\": \"Unused\"\n}\n";

    private const EN_MESSAGES = <<<'PHP'
        <?php

        return [
            'welcome' => 'Welcome',
            'nested' => [
                'title' => 'Title',
                'body' => 'Body',
            ],
            'unused' => 'Nobody',
        ];

        PHP;

    private const ES_MESSAGES = <<<'PHP'
        <?php

        return [
            'welcome' => 'Bienvenido',
        ];

        PHP;

    /** Every test file of the graph, under `tests/`. */
    private const ALL_TESTS = [
        'tests/JsonTest.php',
        'tests/LeafTest.php',
        'tests/PlainTest.php',
        'tests/StarTest.php',
        'tests/SubtreeTest.php',
        'tests/WelcomeTest.php',
    ];

    private const STAR = 'tests/StarTest.php';

    private GitRepo $repo;

    private string $base;

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
        $this->repo->write('lang/en.json', self::EN_JSON);
        $this->repo->write('lang/es.json', "{\"Hello\": \"Hola\"}\n");
        $this->repo->write('lang/en/messages.php', self::EN_MESSAGES);
        $this->repo->write('lang/es/messages.php', self::ES_MESSAGES);
        $this->repo->write('lang/vendor/nova/es.json', "{\"Save\": \"Guardar\"}\n");
        $this->repo->write('lang/README.md', "Translations.\n");
        $this->repo->write('resources/lang/en/legacy.php', "<?php\n\nreturn ['old' => 'Old'];\n");
        $this->repo->write('app/Thing.php', "<?php\n");
        $this->base = $this->repo->commitAll('translations');
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    #[Group('AC-1')]
    public function test_a_json_key_selects_the_files_that_resolved_it_in_that_locale_and_the_untrackable_ones(): void
    {
        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));

        [$selection, $context] = $this->apply();

        self::assertSame(['tests/JsonTest.php', self::STAR], $selection->testFiles(), '`*` is selected by any key change');
        self::assertEquals([new Reason('Translation', 'lang/en.json', 'key "Hello" (en)')], $selection->reasons()['tests/JsonTest.php']);
        self::assertEquals([new Reason('Translation', 'lang/en.json', 'resolved translations untrackably')], $selection->reasons()[self::STAR]);
        self::assertSame([], $context->remaining);
        self::assertSame([], $selection->notes());
    }

    #[Group('AC-1')]
    public function test_a_php_key_selects_by_its_group_key(): void
    {
        $this->repo->write('lang/en/messages.php', str_replace("'Welcome'", "'Welcome!'", self::EN_MESSAGES));

        [$selection] = $this->apply();

        self::assertSame([self::STAR, 'tests/WelcomeTest.php'], $selection->testFiles());
        self::assertEquals([new Reason('Translation', 'lang/en/messages.php', 'key "messages.welcome" (en)')], $selection->reasons()['tests/WelcomeTest.php']);
    }

    /**
     * The reason names three changed keys, then counts the rest.
     *
     * @param list<string> $changed
     */
    #[DataProvider('manyKeys')]
    public function test_a_reason_names_three_keys_and_counts_the_rest(array $changed, string $detail): void
    {
        $data = [];

        foreach (['a', 'b', 'c', 'd', 'e'] as $key) {
            $data[$key] = in_array($key, $changed, true) ? 'changed' : $key;
        }

        $this->repo->write('lang/en.json', json_encode($data, JSON_THROW_ON_ERROR));
        $this->base = $this->repo->commitAll('five keys');
        $this->repo->write('lang/en.json', json_encode(array_map(static fn (string $value): string => $value === 'changed' ? 'x' : $value, $data), JSON_THROW_ON_ERROR));
        $graph = $this->graph();
        $graph->unionTestTranslations(['tests/JsonTest.php' => ['en:a', 'en:b', 'en:c', 'en:d', 'en:e']]);

        [$selection] = $this->apply($graph);

        self::assertEquals([new Reason('Translation', 'lang/en.json', $detail)], $selection->reasons()['tests/JsonTest.php']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function manyKeys(): iterable
    {
        yield 'three' => [['a', 'b', 'c'], 'keys "a", "b", "c" (en)'];
        yield 'four' => [['a', 'b', 'c', 'd'], 'keys "a", "b", "c" and 1 more (en)'];
        yield 'five' => [['a', 'b', 'c', 'd', 'e'], 'keys "a", "b", "c" and 2 more (en)'];
    }

    #[Group('AC-1')]
    public function test_a_nested_key_selects_who_asked_for_it_or_for_its_subtree(): void
    {
        $this->repo->write('lang/en/messages.php', str_replace("'Title'", "'Heading'", self::EN_MESSAGES));

        [$selection] = $this->apply();

        self::assertSame(['tests/LeafTest.php', self::STAR, 'tests/SubtreeTest.php'], $selection->testFiles());
        self::assertEquals([new Reason('Translation', 'lang/en/messages.php', 'key "messages.nested.title" (en)')], $selection->reasons()['tests/SubtreeTest.php']);

        $this->repo->write('lang/en/messages.php', str_replace("'Body'", "'Text'", self::EN_MESSAGES));

        [$selection] = $this->apply();

        self::assertSame([self::STAR, 'tests/SubtreeTest.php'], $selection->testFiles(), 'the sibling leaf is not LeafTest\'s');
    }

    #[Group('AC-1')]
    public function test_a_leaf_turned_into_a_subtree_reaches_who_read_a_key_under_it(): void
    {
        $this->repo->write('lang/en/messages.php', str_replace("'welcome' => 'Welcome'", "'welcome' => ['short' => 'Hi']", self::EN_MESSAGES));
        $graph = $this->graph();
        $graph->unionTestTranslations(['tests/ShortTest.php' => ['en:messages.welcome.short']]);

        [$selection] = $this->apply($graph);

        self::assertSame(['tests/ShortTest.php', self::STAR, 'tests/WelcomeTest.php'], $selection->testFiles());
        self::assertEquals([new Reason('Translation', 'lang/en/messages.php', 'keys "messages.welcome", "messages.welcome.short" (en)')], $selection->reasons()['tests/WelcomeTest.php']);
        self::assertEquals([new Reason('Translation', 'lang/en/messages.php', 'keys "messages.welcome", "messages.welcome.short" (en)')], $selection->reasons()['tests/ShortTest.php']);
    }

    #[Group('AC-1')]
    public function test_the_locale_of_the_file_must_be_the_locale_recorded(): void
    {
        $this->repo->write('lang/es/messages.php', str_replace("'Bienvenido'", "'Bienvenida'", self::ES_MESSAGES));

        [$selection] = $this->apply();

        self::assertSame([self::STAR, 'tests/WelcomeTest.php'], $selection->testFiles());
        self::assertEquals([new Reason('Translation', 'lang/es/messages.php', 'key "messages.welcome" (es)')], $selection->reasons()['tests/WelcomeTest.php']);

        $this->repo->write('lang/es/messages.php', self::ES_MESSAGES);
        $this->repo->write('lang/es.json', "{\"Hello\": \"Buenas\"}\n");

        [$selection] = $this->apply();

        self::assertSame([self::STAR], $selection->testFiles(), 'JsonTest read `Hello` in `en` only');
    }

    #[Group('AC-1')]
    public function test_a_key_nobody_resolved_selects_only_the_untrackable_files(): void
    {
        $this->repo->write('lang/en/messages.php', str_replace("'Nobody'", "'Still nobody'", self::EN_MESSAGES));

        [$selection] = $this->apply();

        self::assertSame([self::STAR], $selection->testFiles());

        $graph = $this->graph();
        $graph->unionTestTranslations([], [self::STAR]);

        [$selection] = $this->apply($graph);

        self::assertSame([], $selection->testFiles(), 'without a `*` file, nothing');
    }

    #[Group('AC-1')]
    public function test_formatting_order_and_comments_only_select_nothing_and_say_so(): void
    {
        $this->repo->write('lang/en.json', '{"Unused":"Unused","Hello":"Hello"}');
        $this->repo->write('lang/en/messages.php', "<?php\n\n// reordered\nreturn ['unused' => 'Nobody', 'nested' => ['body' => 'Body', 'title' => 'Title'], 'welcome' => \"Welcome\"];\n");

        [$selection, $context] = $this->apply(changed: ['lang/en.json', 'lang/en/messages.php']);

        self::assertSame([], $selection->testFiles());
        self::assertSame([], $context->remaining);
        self::assertEquals([
            new Reason('Translation', 'lang/en.json', TranslationRule::FORMATTING_ONLY),
            new Reason('Translation', 'lang/en/messages.php', TranslationRule::FORMATTING_ONLY),
        ], $selection->notes());
    }

    public function test_the_older_resources_lang_root_is_read_the_same_way(): void
    {
        $this->repo->write('resources/lang/en/legacy.php', "<?php\n\nreturn ['old' => 'Older'];\n");
        $graph = $this->graph();
        $graph->unionTestTranslations(['tests/LegacyTest.php' => ['en:legacy.old']]);

        [$selection] = $this->apply($graph, ['resources/lang/en/legacy.php']);

        self::assertSame(['tests/LegacyTest.php', self::STAR], $selection->testFiles());
    }

    /** @return iterable<string, array{string, ?string, string}> path, new content (null: deleted), why */
    public static function uncomparable(): iterable
    {
        yield 'vendor' => ['lang/vendor/nova/es.json', "{\"Save\": \"Guardar ya\"}\n", 'vendor package translations'];
        yield 'other' => ['lang/README.md', "Changed.\n", 'not a translation file'];
        yield 'broken JSON' => ['lang/en.json', '{"Hello": ', 'cannot be read without running it'];
        yield 'not a literal array' => ['lang/en/messages.php', "<?php\n\nreturn ['welcome' => strtoupper('w')];\n", 'cannot be read without running it'];
        yield 'new file' => ['lang/fr.json', "{\"Hello\": \"Bonjour\"}\n", 'new file: no version at the base'];
        yield 'deleted file' => ['lang/es/messages.php', null, 'deleted file'];
    }

    #[DataProvider('uncomparable')]
    public function test_what_cannot_be_compared_selects_every_test_for_that_file_only(string $path, ?string $content, string $why): void
    {
        if ($content === null) {
            $this->repo->delete($path);
        } else {
            $this->repo->write($path, $content);
        }

        $changed = [$path];

        if ($path !== 'lang/en.json') {
            // A comparable change next to it still selects by key.
            $this->repo->write('lang/en.json', str_replace('"Unused": "Unused"', '"Unused": "Still"', self::EN_JSON));
            $changed[] = 'lang/en.json';
        }

        [$selection, $context] = $this->apply(changed: $changed);

        self::assertSame(self::ALL_TESTS, $selection->testFiles());
        self::assertSame([], $context->remaining);

        $reason = new Reason('Translation', $path, 'cannot compare (' . $why . '): every test');

        foreach (self::ALL_TESTS as $testFile) {
            self::assertContainsEquals($reason, $selection->reasons()[$testFile], $testFile);
        }

        if ($path !== 'lang/en.json') {
            self::assertEquals(
                [$reason, new Reason('Translation', 'lang/en.json', 'resolved translations untrackably')],
                $selection->reasons()[self::STAR],
                'the comparable file still selects by key',
            );
        }
    }

    public function test_a_file_that_cannot_be_read_cannot_be_compared(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads a file whatever its mode');
        }

        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));
        chmod($this->repo->path('lang/en.json'), 0o000);

        try {
            [$selection] = $this->apply();
        } finally {
            chmod($this->repo->path('lang/en.json'), 0o644);
        }

        self::assertSame(self::ALL_TESTS, $selection->testFiles());
        self::assertEquals([new Reason('Translation', 'lang/en.json', 'cannot compare (cannot be read): every test')], $selection->reasons()['tests/JsonTest.php']);
    }

    public function test_without_a_base_nothing_can_be_compared(): void
    {
        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));

        [$selection] = $this->apply(base: null);

        self::assertSame(self::ALL_TESTS, $selection->testFiles());
        self::assertEquals([new Reason('Translation', 'lang/en.json', 'cannot compare (no base to compare with): every test')], $selection->reasons()['tests/PlainTest.php']);
    }

    public function test_a_graph_that_recorded_no_translation_key_cannot_narrow(): void
    {
        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));
        $graph = new Graph($this->repo->root);
        $graph->markKnownTestFiles(self::ALL_TESTS);

        [$selection] = $this->apply($graph);

        self::assertSame(self::ALL_TESTS, $selection->testFiles());
        self::assertEquals([new Reason('Translation', 'lang/en.json', 'cannot compare (no translation keys recorded yet): every test')], $selection->reasons()['tests/JsonTest.php']);
    }

    public function test_every_test_is_every_test_of_the_testsuites_targets_only(): void
    {
        $this->repo->write('lang/README.md', "Changed.\n");
        $graph = $this->graph();
        $graph->markKnownTestFiles(['other/OutsideTest.php']);

        [$selection] = $this->apply($graph, ['lang/README.md']);

        self::assertSame(self::ALL_TESTS, $selection->testFiles(), 'a file outside the testsuites is not a target');

        $graph->unionTestTranslations(['other/OutsideTest.php' => ['en:Hello']]);

        [$selection] = $this->apply($graph, ['lang/README.md']);

        self::assertSame(['other/OutsideTest.php', ...self::ALL_TESTS], $selection->testFiles(), 'unless it resolved a translation');
    }

    public function test_it_is_additive_and_leaves_other_paths_alone(): void
    {
        $this->repo->write('lang/en.json', str_replace('"Hello": "Hello"', '"Hello": "Hi"', self::EN_JSON));
        $selection = new Selection();
        $context = $this->context($this->graph(), ['app/Thing.php', 'lang/en.json'], $selection, $this->base);
        $context->consume('lang/en.json');

        (new TranslationRule())->apply($context);

        self::assertSame(['tests/JsonTest.php', self::STAR], $selection->testFiles(), 'claimed already (an edge): still read');
        self::assertSame(['app/Thing.php'], $context->remaining);
    }

    public function test_its_name(): void
    {
        self::assertSame('Translation', (new TranslationRule())->name());
    }

    private function graph(): Graph
    {
        $graph = new Graph($this->repo->root);
        $graph->markKnownTestFiles(self::ALL_TESTS);
        $graph->unionTestTranslations([
            'tests/JsonTest.php' => ['en:Hello'],
            'tests/WelcomeTest.php' => ['en:messages.welcome', 'es:messages.welcome'],
            'tests/SubtreeTest.php' => ['en:messages.nested'],
            'tests/LeafTest.php' => ['en:messages.nested.title'],
            self::STAR => ['*'],
        ]);

        return $graph;
    }

    /**
     * @param list<string> $changed
     * @return array{Selection, Context}
     */
    private function apply(?Graph $graph = null, array $changed = [], string|null $base = 'BASE'): array
    {
        if ($changed === []) {
            $listed = $this->repo->git('diff', '--name-only', 'HEAD') . "\n" . $this->repo->git('ls-files', '--others', '--exclude-standard');
            $changed = array_values(array_filter(array_map(trim(...), explode("\n", $listed)), static fn (string $path): bool => $path !== ''));
        }

        $selection = new Selection();
        $context = $this->context($graph ?? $this->graph(), $changed, $selection, $base === 'BASE' ? $this->base : $base);

        (new TranslationRule())->apply($context);

        return [$selection, $context];
    }

    /** @param list<string> $changed */
    private function context(Graph $graph, array $changed, Selection $selection, ?string $base): Context
    {
        return new Context(
            $graph,
            $this->repo->root,
            new TestPaths(['tests'], [], ['Test.php']),
            new WatchPatterns(),
            $changed,
            $selection,
            $base,
        );
    }
}
