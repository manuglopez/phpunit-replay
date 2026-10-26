<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Translation keys recorded per test file (`translations => 'per-key'`, `test_translations` in
 * the graph), end to end against the real `laravel-lite` fixture: which `<locale>:<key>` pairs
 * each file of `tests/Feature/Translations/` resolved, through the wrapper, the in-process
 * extension and Paratest. The expected entries are the fixture's own contract
 * (`tests/Fixtures/Projects/laravel-lite/README.md`, "Translations"). Skipped when the
 * fixture's `vendor/` was never installed.
 */
final class LaravelLiteTranslationsTest extends TestCase
{
    /**
     * Every file of the fixture that asks for a translation, with exactly what it asks for. The
     * fallback chain shows: `en` is the fallback of `es`, so every `es` lookup is also an `en`
     * one (`trans_choice` included); a lookup in `en` records `en` alone (fallback = locale).
     */
    private const EXPECTED = [
        'tests/Feature/Translations/GroupKeyTest.php' => ['en:messages.welcome', 'es:messages.welcome'],
        'tests/Feature/Translations/GroupSubtreeTest.php' => ['en:messages.nested'],
        'tests/Feature/Translations/JsonKeyTest.php' => ['en:Greetings, :name', 'es:Greetings, :name'],
        'tests/Feature/Translations/PluralFallbackTest.php' => [
            'en:messages.apples',
            'en:messages.only_en',
            'en:messages.welcome',
            'es:messages.apples',
            'es:messages.only_en',
            'es:messages.welcome',
        ],
        'tests/Feature/Translations/SwappedTranslatorTest.php' => ['*'],
        'tests/Feature/Translations/ValidationMessageTest.php' => [
            // The validator reads whole groups (`attributes`, `custom`) besides the rule's own line.
            'en:validation.attributes',
            'en:validation.custom',
            'en:validation.custom.name.required',
            'en:validation.required',
        ],
    ];

    /** Files that must have no entry: they never asked for a translation. */
    private const WITHOUT_ENTRY = [
        'tests/Feature/HomePageTest.php',
        'tests/Feature/PostJsonTest.php',
        'tests/Feature/PostsIndexTest.php',
        'tests/Feature/Translations/NoTranslationsTest.php',
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

    #[Group('AC-I1-a')]
    public function test_record_stores_the_keys_each_test_file_resolved_with_the_fallback_chain(): void
    {
        $fixture = $this->recordedByTheWrapper();

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);

        self::assertSame(self::EXPECTED, $graph->testTranslations());

        foreach (self::WITHOUT_ENTRY as $testFile) {
            self::assertTrue($graph->knowsTest($testFile), $testFile . ' was recorded');
            self::assertSame([], $graph->translationsOf($testFile), $testFile);
            self::assertFalse($graph->translationsUntrackable($testFile), $testFile);
        }

        self::assertSame(['en:messages.apples', 'en:messages.only_en', 'en:messages.welcome', 'es:messages.apples', 'es:messages.only_en', 'es:messages.welcome'], $graph->translationsOf('tests/Feature/Translations/PluralFallbackTest.php'));
        self::assertTrue($graph->translationsUntrackable('tests/Feature/Translations/SwappedTranslatorTest.php'));
        self::assertSame([], $graph->translationsOf('tests/Feature/Translations/SwappedTranslatorTest.php'), '`*` is not a key');
    }

    #[Group('AC-I1-b')]
    public function test_the_in_process_extension_records_the_same_entries_as_the_wrapper(): void
    {
        $wrapper = ReplayAssert::loadGraph($this->recordedByTheWrapper());

        $fixture = $this->fixture();
        $fixture->write('phpunit.inprocess.xml', str_replace(
            '<testsuites>',
            "<extensions>\n        <bootstrap class=\"Manuglopez\\Replay\\PHPUnit\\ReplayExtension\">\n            <parameter name=\"mode\" value=\"record\"/>\n        </bootstrap>\n    </extensions>\n    <testsuites>",
            $fixture->read('phpunit.xml'),
        ));
        $fixture->repo->commitAll('in-process configuration');

        $run = $fixture->phpunitInProcess(['-c', 'phpunit.inprocess.xml']);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);

        $inProcess = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($wrapper);
        self::assertNotNull($inProcess);
        self::assertSame(self::EXPECTED, $inProcess->testTranslations());
        self::assertSame($wrapper->testTranslations(), $inProcess->testTranslations());
    }

    #[DataProvider('workerCounts')]
    #[Group('AC-I1-b')]
    public function test_paratest_workers_record_the_same_entries_joined_from_their_partials(int $workers): void
    {
        FixtureProject::skipUnlessParatestUsable(dirname(__DIR__) . '/Fixtures/Projects/laravel-lite/vendor');

        $fixture = $this->fixture();
        $recorded = $fixture->replay(['record', '--parallel=' . $workers]);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        self::assertSame(self::EXPECTED, $graph->testTranslations(), $workers . ' workers');
    }

    /** @return iterable<string, array{int}> */
    public static function workerCounts(): iterable
    {
        yield '2 workers' => [2];
        yield '4 workers' => [4];
    }

    #[Group('AC-I1-c')]
    public function test_a_later_recording_keeps_the_older_entries_and_what_the_file_now_reads_is_added(): void
    {
        $fixture = $this->recordedByTheWrapper();

        $fixture->write('tests/Feature/Translations/GroupSubtreeTest.php', str_replace(
            "trans('messages.nested')",
            "trans('messages.nested.title') === 'Nested title' ? trans('messages.nested') : []",
            $fixture->read('tests/Feature/Translations/GroupSubtreeTest.php'),
        ));
        $again = $fixture->replay(['record']);
        self::assertSame(0, $again['exitCode'], $again['stdout'] . $again['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        self::assertSame(
            ['en:messages.nested', 'en:messages.nested.title'],
            $graph->translationsOf('tests/Feature/Translations/GroupSubtreeTest.php'),
            'the old entry stays, the new one joins it',
        );
        self::assertSame(self::EXPECTED['tests/Feature/Translations/GroupKeyTest.php'], $graph->translationsOf('tests/Feature/Translations/GroupKeyTest.php'));
    }

    #[Group('AC-I1-c')]
    public function test_a_deleted_test_file_takes_its_entries_with_it(): void
    {
        $fixture = $this->recordedByTheWrapper();

        $fixture->delete('tests/Feature/Translations/JsonKeyTest.php');
        $fixture->delete('tests/Feature/Translations/SwappedTranslatorTest.php');
        $again = $fixture->replay(['record']);
        self::assertSame(0, $again['exitCode'], $again['stdout'] . $again['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);

        $expected = self::EXPECTED;
        unset($expected['tests/Feature/Translations/JsonKeyTest.php'], $expected['tests/Feature/Translations/SwappedTranslatorTest.php']);
        self::assertSame($expected, $graph->testTranslations());
    }

    #[Group('AC-I1-c')]
    public function test_status_shows_how_many_distinct_keys_were_recorded(): void
    {
        $fixture = $this->recordedByTheWrapper();

        $status = $fixture->replay(['status']);

        self::assertSame(0, $status['exitCode'], $status['stdout'] . $status['stderr']);
        // en:/es: messages.welcome, messages.apples, messages.only_en (6), en:messages.nested,
        // en:/es: "Greetings, :name" (2), the four validation keys: 13 distinct; `*` not counted.
        self::assertStringContainsString("translations: 13\n", $status['stdout']);
    }

    #[Group('AC-I1-d')]
    public function test_conservative_mode_arms_nothing_and_records_no_entries(): void
    {
        $fixture = $this->fixture();
        $fixture->write('phpunit-replay.php', "<?php\n\nreturn ['translations' => 'conservative'];\n");
        $fixture->repo->commitAll('conservative translations');

        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        self::assertSame([], $graph->testTranslations());
        self::assertTrue($graph->knowsTest('tests/Feature/Translations/JsonKeyTest.php'));
    }

    #[Group('AC-I1-d')]
    public function test_laravel_off_arms_nothing(): void
    {
        $fixture = $this->fixture();
        $fixture->write('phpunit-replay.php', "<?php\n\nreturn ['laravel' => 'off'];\n");
        $fixture->repo->commitAll('laravel off');

        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        self::assertSame([], $graph->testTranslations());
    }

    #[Group('AC-I1-f')]
    public function test_the_fixture_suite_passes_with_and_without_recording(): void
    {
        $fixture = $this->fixture();

        $plain = $fixture->phpunit();
        self::assertSame(0, $plain['exitCode'], $plain['stdout'] . $plain['stderr']);
        self::assertStringContainsString('OK (12 tests', $plain['stdout']);

        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        self::assertStringContainsString('OK (12 tests', $recorded['stdout']);
    }

    private function fixture(): FixtureProject
    {
        return $this->fixtures[] = FixtureProject::laravelLite();
    }

    private function recordedByTheWrapper(): FixtureProject
    {
        $fixture = $this->fixture();
        $recorded = $fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        return $fixture;
    }
}
