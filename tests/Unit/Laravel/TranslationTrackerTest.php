<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Closure;
use Illuminate\Translation\Translator;
use Manuglopez\Replay\Laravel\RecordingTranslator;
use Manuglopez\Replay\Laravel\TranslationTracker;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Tests\Unit\Laravel\Fixtures\FakeTranslatorApp;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * `TranslationTracker` is duck-typed against an Illuminate application, like `TableTracker`
 * and `BladeTracker`: exercised here against a fake container with the handful of methods it
 * calls (`extend`, `resolved`, `make`), and the stand-in translator of
 * `Fixtures/IlluminateTranslatorStub.php`. D-2 cases (a), (b) and (c) are the `*` tests.
 */
final class TranslationTrackerTest extends TestCase
{
    private const TEST_FILE = '/project/tests/FooTest.php';

    private Recorder $recorder;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateTranslatorStub.php';

        $this->recorder = new Recorder(new FakeCoverageDriver([[]]));
        $this->recorder->beginTest(self::TEST_FILE);
    }

    #[Group('AC-I1-a')]
    public function test_the_translator_is_replaced_when_it_is_resolved_and_records_what_it_serves(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => self::translator('es', 'en'));

        TranslationTracker::arm($app, $this->recorder);

        self::assertSame([], $this->recorded(), 'arming resolves nothing');
        self::assertFalse($app->resolved('translator'), 'and does not force the translator to load');

        $translator = $app->make('translator');
        self::assertInstanceOf(RecordingTranslator::class, $translator);
        self::assertSame($translator, $app->make('translator'), 'still one shared instance');
        $translator->get('messages.welcome');

        self::assertSame(['en:messages.welcome', 'es:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_case_a_a_translator_resolved_before_arming_is_not_traceable(): void
    {
        $original = self::translator('es', 'en');
        $app = new FakeTranslatorApp(static fn (): Translator => $original);
        $app->make('translator');

        TranslationTracker::arm($app, $this->recorder);

        self::assertSame(['*'], $this->recorded());
        self::assertSame($original, $app->make('translator'), 'the container is left alone');
    }

    #[Group('AC-I1-e')]
    public function test_arming_twice_does_not_make_the_test_untraceable(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => self::translator('es', 'en'));
        TranslationTracker::arm($app, $this->recorder);
        $app->make('translator');

        TranslationTracker::arm($app, $this->recorder);

        self::assertSame([], $this->recorded());
        self::assertSame(1, $app->extenders, 'the second arm does not extend again');
    }

    #[Group('AC-I1-e')]
    public function test_case_b_a_subclass_of_the_translator_is_left_alone_and_not_traceable(): void
    {
        $custom = new class(new \stdClass(), 'es') extends Translator {};
        $app = new FakeTranslatorApp(static fn (): object => $custom);
        TranslationTracker::arm($app, $this->recorder);

        self::assertSame([], $this->recorded(), 'nothing is known until it is resolved');

        self::assertSame($custom, $app->make('translator'));
        self::assertSame(['*'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_case_b_something_that_is_not_a_translator_is_left_alone_and_not_traceable(): void
    {
        $mock = new \stdClass();
        $app = new FakeTranslatorApp(static fn (): object => $mock);
        TranslationTracker::arm($app, $this->recorder);

        self::assertSame($mock, $app->make('translator'));
        self::assertSame(['*'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_a_container_that_cannot_be_extended_is_not_traceable(): void
    {
        TranslationTracker::arm(new \stdClass(), $this->recorder);

        self::assertSame(['*'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_a_container_that_cannot_say_whether_it_resolved_is_not_traceable(): void
    {
        $app = new class {
            public function extend(string $abstract, Closure $closure): void
            {
            }
        };

        TranslationTracker::arm($app, $this->recorder);

        self::assertSame(['*'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_arming_twice_before_the_translator_is_built_wraps_it_once_and_stays_traceable(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => self::translator('es', 'en'));
        TranslationTracker::arm($app, $this->recorder);
        TranslationTracker::arm($app, $this->recorder);

        $translator = $app->make('translator');
        self::assertInstanceOf(RecordingTranslator::class, $translator);
        $translator->get('messages.welcome');

        self::assertSame(['en:messages.welcome', 'es:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_case_c_a_translator_swapped_during_the_test_is_not_traceable(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => self::translator('es', 'en'));
        TranslationTracker::arm($app, $this->recorder);
        $app->make('translator');

        $app->swap('translator', new \stdClass());
        TranslationTracker::verify($app, $this->recorder);

        self::assertSame(['*'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_case_c_an_untouched_recording_translator_is_traceable(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => self::translator('es', 'en'));
        TranslationTracker::arm($app, $this->recorder);
        $app->make('translator')->get('messages.welcome');

        TranslationTracker::verify($app, $this->recorder);

        self::assertSame(['en:messages.welcome', 'es:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_verifying_does_not_load_a_translator_the_test_never_used(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => self::translator('es', 'en'));
        TranslationTracker::arm($app, $this->recorder);

        TranslationTracker::verify($app, $this->recorder);

        self::assertFalse($app->resolved('translator'));
        self::assertSame([], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_a_container_that_cannot_be_inspected_is_not_traceable(): void
    {
        TranslationTracker::verify(new \stdClass(), $this->recorder);

        self::assertSame(['*'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_a_translator_that_cannot_be_resolved_is_not_traceable(): void
    {
        $app = new FakeTranslatorApp(static fn (): never => throw new RuntimeException('boom'));
        $app->forceResolved('translator');

        TranslationTracker::verify($app, $this->recorder);

        self::assertSame(['*'], $this->recorded());
    }

    private static function translator(string $locale, string $fallback): Translator
    {
        $translator = new Translator(new \stdClass(), $locale);
        $translator->setFallback($fallback);

        return $translator;
    }

    /** @return list<string> */
    private function recorded(): array
    {
        $this->recorder->endTest();
        $entries = $this->recorder->perTestTranslations()[self::TEST_FILE] ?? [];
        $this->recorder->beginTest(self::TEST_FILE);

        return $entries;
    }
}
