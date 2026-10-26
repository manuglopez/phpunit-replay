<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Illuminate\Translation\Translator;
use Manuglopez\Replay\Laravel\RecordingTranslator;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `RecordingTranslator` against a stand-in for Illuminate's translator (see
 * `Fixtures/IlluminateTranslatorStub.php`): which `<locale>:<key>` pairs `get()` records, and
 * that it hands back exactly what the original would have.
 */
final class RecordingTranslatorTest extends TestCase
{
    private Recorder $recorder;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateTranslatorStub.php';
    }

    protected function setUp(): void
    {
        $this->recorder = new Recorder(new FakeCoverageDriver([[]]));
        $this->recorder->beginTest('/project/tests/FooTest.php');
    }

    #[Group('AC-I1-a')]
    public function test_it_records_the_key_for_every_locale_of_the_fallback_chain(): void
    {
        $translator = $this->wrap('es', 'en');

        $translator->get('messages.welcome');

        self::assertSame(['en:messages.welcome', 'es:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-a')]
    public function test_an_explicit_locale_replaces_the_current_one_but_keeps_the_fallback(): void
    {
        $translator = $this->wrap('es', 'en');

        $translator->get('messages.welcome', [], 'fr');

        self::assertSame(['en:messages.welcome', 'fr:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-a')]
    public function test_without_fallback_only_the_requested_locale_is_recorded(): void
    {
        $translator = $this->wrap('es', 'en');

        $translator->get('messages.welcome', [], null, false);
        $translator->get('messages.bye', [], 'fr', false);

        self::assertSame(['es:messages.welcome', 'fr:messages.bye'], $this->recorded());
    }

    #[Group('AC-I1-a')]
    public function test_a_locale_equal_to_the_fallback_is_recorded_once(): void
    {
        $translator = $this->wrap('en', 'en');

        $translator->get('messages.welcome');

        self::assertSame(['en:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-a')]
    public function test_with_no_fallback_locale_only_the_current_one_is_recorded(): void
    {
        $translator = $this->wrap('es', '');

        $translator->get('messages.welcome');

        self::assertSame(['es:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-a')]
    public function test_the_locales_the_application_decides_on_are_the_ones_recorded(): void
    {
        $original = new Translator(new \stdClass(), 'es');
        $original->setFallback('en');
        $original->determineLocalesUsing(static fn (array $locales): array => [...$locales, 'ca']);

        RecordingTranslator::wrapping($original, $this->recorder)->get('messages.welcome');

        self::assertSame(['ca:messages.welcome', 'en:messages.welcome', 'es:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-a')]
    public function test_the_key_is_recorded_literally_namespaced_and_json_keys_included(): void
    {
        $translator = $this->wrap('es', '');

        $translator->get('pkg::messages.nested.title');
        $translator->get('Hello :name');

        self::assertSame(['es:Hello :name', 'es:pkg::messages.nested.title'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_it_returns_what_the_original_returns_and_delegates_to_it(): void
    {
        $original = new Translator(new \stdClass(), 'es');
        $original->setFallback('en');
        $original->preload(['en' => ['messages.only_en' => 'Only in English'], 'es' => ['messages.welcome' => 'Bienvenido']]);
        $reference = clone $original;

        $wrapped = RecordingTranslator::wrapping($original, $this->recorder);

        foreach ([['messages.welcome'], ['messages.only_en'], ['messages.missing'], ['messages.welcome', [], 'en'], ['messages.only_en', [], 'es', false]] as $arguments) {
            self::assertSame($reference->get(...$arguments), $wrapped->get(...$arguments));
        }

        self::assertCount(5, $wrapped->served, 'every call reaches the parent implementation exactly once');
    }

    #[Group('AC-I1-e')]
    public function test_it_is_a_translator_carrying_the_whole_state_of_the_original(): void
    {
        $original = new Translator($loader = new \stdClass(), 'es');
        $original->setFallback('en');
        $original->preload(['es' => ['a' => 'b']]);
        $original->determineLocalesUsing($callback = static fn (array $locales): array => $locales);

        $wrapped = RecordingTranslator::wrapping($original, $this->recorder);

        self::assertInstanceOf(Translator::class, $wrapped);
        self::assertNotSame($original, $wrapped);
        self::assertSame('es', $wrapped->getLocale());
        self::assertSame('en', $wrapped->getFallback());
        self::assertSame($this->stateOf($original), $this->stateOf($wrapped));
        self::assertSame($loader, $this->stateOf($wrapped)['loader']);
        self::assertSame($callback, $this->stateOf($wrapped)['determineLocalesUsing']);
    }

    #[Group('AC-I1-a')]
    public function test_a_lookup_outside_any_test_records_nothing(): void
    {
        $this->recorder->endTest();

        $this->wrap('es', 'en')->get('messages.welcome');

        self::assertSame([], $this->recorder->perTestTranslations());
    }

    #[Group('AC-I1-a')]
    public function test_a_key_that_is_not_a_string_is_delegated_but_not_recorded(): void
    {
        $translator = $this->wrap('es', 'en');

        self::assertSame(['x'], $translator->get(['x']));
        self::assertSame([], $this->recorded());
    }

    private function wrap(string $locale, string $fallback): RecordingTranslator
    {
        $original = new Translator(new \stdClass(), $locale);
        $original->setFallback($fallback);

        return RecordingTranslator::wrapping($original, $this->recorder);
    }

    /** @return list<string> */
    private function recorded(): array
    {
        $this->recorder->endTest();

        return $this->recorder->perTestTranslations()['/project/tests/FooTest.php'] ?? [];
    }

    /** @return array<string, mixed> */
    private function stateOf(Translator $translator): array
    {
        $state = [];

        foreach ((new \ReflectionClass(Translator::class))->getProperties() as $property) {
            if (! $property->isStatic()) {
                $state[$property->getName()] = $property->getValue($translator);
            }
        }

        return $state;
    }
}
