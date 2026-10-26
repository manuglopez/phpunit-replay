<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Laravel\LangFiles;
use Manuglopez\Replay\Laravel\LangPath;
use Manuglopez\Replay\Select\NonEdgeInputs;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Selection by translation key (`Laravel\Rules\TranslationRule`, `translations => 'per-key'`)
 * end to end against the real `laravel-lite` fixture: what a change under `lang/` selects in
 * `run --dry-run`, what a real `run` executes and serves from the cache, and — by mutating
 * every leaf key of every language file — that no test that a change makes fail is ever left
 * out. Which key each test file reads is the fixture's own contract
 * (`tests/Fixtures/Projects/laravel-lite/README.md`, "Translations"), the map
 * {@see LaravelLiteTranslationsTest::EXPECTED} asserts against the recording. Skipped when the
 * fixture's `vendor/` was never installed.
 */
final class LaravelLiteTranslationSelectionTest extends TestCase
{
    /** The file whose translations cannot be traced (`Lang::swap()`): any language change selects it. */
    private const UNTRACKABLE = 'tests/Feature/Translations/SwappedTranslatorTest.php';

    private const ALL_TESTS = [
        'tests/Feature/HomePageTest.php',
        'tests/Feature/PostJsonTest.php',
        'tests/Feature/PostsIndexTest.php',
        'tests/Feature/Translations/GroupKeyTest.php',
        'tests/Feature/Translations/GroupSubtreeTest.php',
        'tests/Feature/Translations/JsonKeyTest.php',
        'tests/Feature/Translations/NoTranslationsTest.php',
        'tests/Feature/Translations/PluralFallbackTest.php',
        'tests/Feature/Translations/SwappedTranslatorTest.php',
        'tests/Feature/Translations/ValidationMessageTest.php',
        'tests/Feature/UserModelTest.php',
    ];

    /** @var list<FixtureProject> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! FixtureProject::laravelLiteAvailable()) {
            self::markTestSkipped('tests/Fixtures/Projects/laravel-lite/vendor is not installed — see its README.md.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        $this->fixtures = [];

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string, array<string, string>}> file, search, replace,
     *         the selection besides the untrackable file (test file => its reason's detail)
     */
    public static function keyChanges(): iterable
    {
        yield 'JSON key' => ['lang/es.json', '"Saludos, :name"', '"Hola, :name"', [
            'tests/Feature/Translations/JsonKeyTest.php' => 'key "Greetings, :name" (es)',
        ]];
        yield 'PHP key, read by two files' => ['lang/es/messages.php', "'Bienvenido'", "'Bienvenida'", [
            'tests/Feature/Translations/GroupKeyTest.php' => 'key "messages.welcome" (es)',
            'tests/Feature/Translations/PluralFallbackTest.php' => 'key "messages.welcome" (es)',
        ]];
        yield 'nested key, read as a subtree' => ['lang/en/messages.php', "'Nested title'", "'Heading'", [
            'tests/Feature/Translations/GroupSubtreeTest.php' => 'key "messages.nested.title" (en)',
        ]];
        yield 'key es falls back to en for' => ['lang/en/messages.php', "'Only in English'", "'English only'", [
            'tests/Feature/Translations/PluralFallbackTest.php' => 'key "messages.only_en" (en)',
        ]];
        yield 'validation line' => ['lang/en/validation.php', "'The :attribute field is required.'", "'Fill in :attribute.'", [
            'tests/Feature/Translations/ValidationMessageTest.php' => 'key "validation.required" (en)',
        ]];
        yield 'unused JSON key' => ['lang/en.json', '"Nobody uses this JSON key": "Nobody uses this JSON key"', '"Nobody uses this JSON key": "Still nobody"', []];
        yield 'unused PHP key' => ['lang/es/messages.php', "'Nadie lee esto'", "'Nadie lo lee'", []];
    }

    /** @param array<string, string> $selected */
    #[DataProvider('keyChanges')]
    #[Group('AC-1')]
    public function test_a_key_change_selects_the_test_files_that_read_it_and_the_untrackable_one(string $file, string $search, string $replace, array $selected): void
    {
        $fixture = $this->recorded();
        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        $before = $this->digests($fixture, $graph);

        $this->replaceIn($fixture, $file, $search, $replace);

        $plan = $this->plan($fixture);

        $expected = [];

        foreach ($selected as $testFile => $detail) {
            $expected[$testFile] = 'Translation ' . $file . ' (' . $detail . ')';
        }

        // SwappedTranslatorTest resolved its translations untrackably (`*`): any change of
        // a language file selects it, the key it changes read by anyone or by nobody.
        $expected[self::UNTRACKABLE] = 'Translation ' . $file . ' (resolved translations untrackably)';
        ksort($expected);

        self::assertSame($expected, $plan);

        // The stamps agree: the digest moves for exactly those files.
        $moved = array_keys(array_diff_assoc($this->digests($fixture, $graph), $before));
        sort($moved);
        self::assertSame(array_keys($expected), $moved);

        // A real run executes them and serves every other one from the cache.
        $run = $fixture->replay(['run']);
        $methods = array_sum(array_map(self::methodsIn(...), array_keys($expected)));
        self::assertSame($methods, ReplayAssert::executedCount($run['stdout']), $run['stdout'] . $run['stderr']);
        self::assertSame(12 - $methods, ReplayAssert::replayedCount($run['stdout']), $run['stdout']);
    }

    #[Group('AC-1')]
    public function test_an_unused_key_selects_nothing_without_an_untrackable_file(): void
    {
        $fixture = $this->fixture();
        $fixture->delete(self::UNTRACKABLE);
        $fixture->repo->commitAll('no untrackable test file');
        $this->record($fixture);

        $this->replaceIn($fixture, 'lang/en.json', '"Nobody uses this JSON key": "Nobody uses this JSON key"', '"Nobody uses this JSON key": "Still nobody"');

        self::assertSame([], $this->plan($fixture));

        $run = $fixture->replay(['run']);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($run['stdout']), $run['stdout']);
        self::assertSame(11, ReplayAssert::replayedCount($run['stdout']), $run['stdout']);
    }

    #[Group('AC-1')]
    public function test_formatting_order_and_comments_select_nothing_and_say_so(): void
    {
        $fixture = $this->recorded();

        $fixture->write('lang/en.json', "{\"Nobody uses this JSON key\":\"Nobody uses this JSON key\",\n\"Greetings, :name\":\"Greetings, :name\"}");
        $fixture->write('lang/es/messages.php', "<?php\n\n// Reordered, and \"double\" quoted.\nreturn [\n    \"unused\" => \"Nadie lee esto\",\n    'nested' => ['body' => 'Cuerpo anidado', 'title' => 'Título anidado'],\n    'apples' => '{0} Ninguna manzana|{1} Una manzana|[2,*] :count manzanas',\n    'welcome' => 'Bienvenido',\n];\n");

        $dryRun = $fixture->replay(['run', '--dry-run']);

        self::assertSame(0, $dryRun['exitCode'], $dryRun['stdout'] . $dryRun['stderr']);
        self::assertSame([], $this->planOf($dryRun['stdout']), $dryRun['stdout']);
        self::assertMatchesRegularExpression('#^lang/en\.json\s+← Translation only formatting/order/comments: selects nothing$#m', $dryRun['stdout']);
        self::assertMatchesRegularExpression('#^lang/es/messages\.php\s+← Translation only formatting/order/comments: selects nothing$#m', $dryRun['stdout']);

        $run = $fixture->replay(['run']);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($run['stdout']), $run['stdout']);
    }

    /** @return iterable<string, array{string, ?string, string}> file, its new content (null: delete), why it cannot be compared */
    public static function uncomparableChanges(): iterable
    {
        yield 'vendor package' => ['lang/vendor/nova/es.json', "{\"Save\": \"Guardar\"}\n", 'vendor package translations'];
        yield 'not a translation file' => ['lang/README.md', "Translations.\n", 'not a translation file'];
        yield 'broken syntax' => ['lang/en.json', '{"Greetings, :name": ', 'cannot be read without running it'];
    }

    #[DataProvider('uncomparableChanges')]
    public function test_what_cannot_be_compared_selects_every_test_and_says_why(string $file, string $content, string $why): void
    {
        $fixture = $this->recorded();

        $fixture->write($file, $content);

        $plan = $this->plan($fixture);

        self::assertSame(self::ALL_TESTS, array_keys($plan));
        self::assertSame(['Translation ' . $file . ' (cannot compare (' . $why . '): every test)'], array_values(array_unique($plan)));
    }

    public function test_conservative_mode_runs_every_test_on_any_language_change_as_before(): void
    {
        $fixture = $this->fixture();
        $fixture->write('phpunit-replay.php', "<?php\n\nreturn ['translations' => 'conservative'];\n");
        $fixture->repo->commitAll('conservative translations');
        $this->record($fixture);

        $this->replaceIn($fixture, 'lang/en.json', '"Nobody uses this JSON key": "Nobody uses this JSON key"', '"Nobody uses this JSON key": "Still nobody"');

        $plan = $this->plan($fixture);

        self::assertSame(self::ALL_TESTS, array_keys($plan));
        self::assertStringStartsWith('Watch', $plan['tests/Feature/HomePageTest.php']);
    }

    /**
     * No false negative: for every leaf key of every language file of the fixture, enumerated
     * with `LangFiles::flatten`, a copy with that value changed runs the fixture's suite
     * without replay (its tests assert the exact text: the oracle of who reads it), and every
     * test file that fails is in what `run --dry-run` selects — which is, exactly, what the
     * README's map of keys per test file says.
     */
    #[Group('AC-2')]
    public function test_every_leaf_key_change_selects_every_test_file_it_makes_fail(): void
    {
        $fixture = $this->recorded();
        $leaves = 0;
        $failingSomewhere = [];

        foreach ($this->languageFiles($fixture) as $file => $langPath) {
            $original = $fixture->read($file);
            $flat = LangFiles::flatten($original, $langPath);
            self::assertNotNull($flat, $file);

            foreach (array_keys($flat) as $key) {
                $key = (string) $key;
                $mutated = self::mutate($original, $langPath, $key);
                self::assertSame([$key], LangFiles::changedKeys($original, $mutated, $langPath), $file . ' ' . $key . ': one value changed');
                $fixture->write($file, $mutated);

                $failing = $this->failingTestFiles($fixture);
                $selected = array_keys($this->plan($fixture));
                $expected = self::selectedByTheReadme((string) $langPath->locale, $key);

                self::assertSame([], array_values(array_diff($failing, $selected)), $file . ' ' . $key . ': a failing test file was not selected');
                self::assertSame($expected, $selected, $file . ' ' . $key . ': the README map');

                $failingSomewhere = [...$failingSomewhere, ...$failing];
                $leaves++;
                $fixture->write($file, $original);
            }
        }

        // 2 + 2 JSON keys, 6 + 5 messages entries, 2 validation entries.
        self::assertSame(17, $leaves);
        // The oracle saw every test file that reads a key fail at least once.
        $failingSomewhere = array_values(array_unique($failingSomewhere));
        sort($failingSomewhere);
        self::assertSame([
            'tests/Feature/Translations/GroupKeyTest.php',
            'tests/Feature/Translations/GroupSubtreeTest.php',
            'tests/Feature/Translations/JsonKeyTest.php',
            'tests/Feature/Translations/PluralFallbackTest.php',
            'tests/Feature/Translations/ValidationMessageTest.php',
        ], $failingSomewhere);
    }

    /**
     * What the README's map says a change of `<locale>:<key>` selects: every test file with
     * an entry of that locale that {@see LangFiles::matches()} it, and the untrackable one.
     *
     * @return list<string>
     */
    private static function selectedByTheReadme(string $locale, string $key): array
    {
        $out = [];

        foreach (LaravelLiteTranslationsTest::EXPECTED as $testFile => $entries) {
            foreach ($entries as $entry) {
                if ($entry === '*' || (str_starts_with($entry, $locale . ':') && LangFiles::matches(substr($entry, strlen($locale) + 1), $key))) {
                    $out[] = $testFile;

                    break;
                }
            }
        }

        sort($out);

        return $out;
    }

    /** `$content` with the value of `$key` (flattened, the group included) replaced. */
    private static function mutate(string $content, LangPath $langPath, string $key): string
    {
        if ($langPath->kind === LangPath::JSON) {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            $data[$key] = 'Mutated ' . $key;

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }

        // The fixture's own PHP files, run here on purpose: they are literal arrays.
        $copy = tempnam(sys_get_temp_dir(), 'lang');
        self::assertIsString($copy);
        file_put_contents($copy, $content);

        try {
            $data = include $copy;
        } finally {
            unlink($copy);
        }

        self::assertIsArray($data);
        $path = explode('.', substr($key, strlen((string) $langPath->group) + 1));
        $node = &$data;

        foreach ($path as $segment) {
            self::assertIsArray($node);
            $node = &$node[$segment];
        }

        $node = 'Mutated ' . $key;
        unset($node);

        return "<?php\n\nreturn " . var_export($data, true) . ";\n";
    }

    /** @return array<string, LangPath> every translation file of the fixture */
    private function languageFiles(FixtureProject $fixture): array
    {
        $files = [];
        $listed = $fixture->repo->git('ls-files', 'lang');

        foreach (array_filter(explode("\n", trim($listed))) as $file) {
            $langPath = LangFiles::classify($file);
            self::assertNotNull($langPath, $file);

            if ($langPath->kind === LangPath::JSON || $langPath->kind === LangPath::PHP) {
                $files[$file] = $langPath;
            }
        }

        self::assertCount(5, $files);

        return $files;
    }

    /** @return list<string> the fixture's test files that fail, run without replay */
    private function failingTestFiles(FixtureProject $fixture): array
    {
        $run = $fixture->phpunit();
        preg_match_all('#^\d+\) (Tests\\\\[\w\\\\]+)::#m', $run['stdout'], $m);

        $files = [];

        foreach (array_unique($m[1]) as $class) {
            $files[] = 'tests/' . str_replace('\\', '/', substr($class, strlen('Tests\\'))) . '.php';
        }

        sort($files);

        if ($files === [] && $run['exitCode'] !== 0) {
            throw new RuntimeException('The fixture suite failed with no test named: ' . $run['stdout'] . $run['stderr']);
        }

        return $files;
    }

    private function replaceIn(FixtureProject $fixture, string $file, string $search, string $replace): void
    {
        $content = $fixture->read($file);
        self::assertStringContainsString($search, $content);
        $fixture->write($file, str_replace($search, $replace, $content));
    }

    private static function methodsIn(string $testFile): int
    {
        $source = file_get_contents(dirname(__DIR__) . '/Fixtures/Projects/laravel-lite/' . $testFile);
        self::assertIsString($source);

        return preg_match_all('#public function test_#', $source);
    }

    private function recorded(): FixtureProject
    {
        $fixture = $this->fixture();
        $this->record($fixture);

        return $fixture;
    }

    private function record(FixtureProject $fixture): void
    {
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    private function fixture(): FixtureProject
    {
        return $this->fixtures[] = FixtureProject::laravelLite();
    }

    /** @return array<string, string> test file => `<rule> <trigger> (<detail>)`, from `run --dry-run` */
    private function plan(FixtureProject $fixture): array
    {
        $dryRun = $fixture->replay(['run', '--dry-run']);
        self::assertSame(0, $dryRun['exitCode'], $dryRun['stdout'] . $dryRun['stderr']);

        return $this->planOf($dryRun['stdout']);
    }

    /** @return array<string, string> */
    private function planOf(string $stdout): array
    {
        preg_match_all('#^(tests/\S+)\s+← (.+?)\s*$#mu', $stdout, $m, PREG_SET_ORDER);
        $plan = [];

        foreach ($m as $line) {
            $plan[$line[1]] = preg_replace('/\s+/', ' ', $line[2]);
        }

        ksort($plan);

        return $plan;
    }

    /** @return array<string, ?string> every recorded test file's non-edge input digest on the current tree */
    private function digests(FixtureProject $fixture, Graph $graph): array
    {
        $root = $fixture->root();
        $inputs = NonEdgeInputs::forProject($graph, $root, Config::load($root), new TestPaths(['tests/Feature'], [], ['Test.php']), new FileHashes($root), new Git($root));
        $out = [];

        foreach ($graph->allTestFiles() as $testFile) {
            $out[$testFile] = $inputs->digestFor($testFile);
        }

        ksort($out);

        return $out;
    }
}
