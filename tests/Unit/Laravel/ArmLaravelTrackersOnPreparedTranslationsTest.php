<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Illuminate\Container\Container;
use Illuminate\Translation\Translator;
use Manuglopez\Replay\Laravel\RecordingTranslator;
use Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPrepared;
use Manuglopez\Replay\Laravel\UsesDatabaseCollector;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Tests\Unit\Laravel\Fixtures\FakeTranslatorApp;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** How the arming subscriber wires the translation tracker (`translations => 'per-key'`). */
final class ArmLaravelTrackersOnPreparedTranslationsTest extends TestCase
{
    private const TEST_FILE = '/project/tests/FooTest.php';

    private Recorder $recorder;

    protected function setUp(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateTranslatorStub.php';
        require_once __DIR__ . '/Fixtures/IlluminateContainerStub.php';

        $this->recorder = new Recorder(new FakeCoverageDriver([[]]));
        $this->recorder->beginTest(self::TEST_FILE);
    }

    #[Group('AC-I1-d')]
    public function test_per_key_arms_the_translator_once_per_application(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => new Translator(new \stdClass(), 'en'));
        $arming = $this->arming(true);

        self::assertSame($app, $arming->armTrackers($app));
        $arming->armTrackers($app);

        self::assertSame(1, $app->extenders, 'the marker binding arms an application once');
        self::assertInstanceOf(RecordingTranslator::class, $app->make('translator'));
    }

    #[Group('AC-I1-d')]
    public function test_conservative_arms_nothing_and_verifies_nothing(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => new Translator(new \stdClass(), 'en'));
        $arming = $this->arming(false);

        $arming->armTrackers($app);
        $app->swap('translator', new \stdClass());
        $arming->verifyTranslations($app);

        self::assertSame(0, $app->extenders);
        self::assertSame([], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_verify_marks_a_swapped_translator_and_leaves_an_untouched_one(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => new Translator(new \stdClass(), 'en'));
        $arming = $this->arming(true);
        $arming->armTrackers($app);
        $app->make('translator')->get('messages.welcome');

        $arming->verifyTranslations($app);
        self::assertSame(['en:messages.welcome'], $this->recorded());

        $app->swap('translator', new \stdClass());
        $arming->verifyTranslations($app);
        self::assertSame(['*', 'en:messages.welcome'], $this->recorded());
    }

    #[Group('AC-I1-e')]
    public function test_verify_finds_the_global_container_when_it_is_not_given_an_application(): void
    {
        $app = new FakeTranslatorApp(static fn (): Translator => new Translator(new \stdClass(), 'en'));
        $arming = $this->arming(true);
        $arming->armTrackers($app);
        $app->swap('translator', new \stdClass());

        Container::$instance = $app;

        try {
            $arming->verifyTranslations();
        } finally {
            Container::$instance = null;
        }

        self::assertSame(['*'], $this->recorded());
    }

    private function arming(bool $trackTranslations): ArmLaravelTrackersOnPrepared
    {
        return new ArmLaravelTrackersOnPrepared($this->recorder, new UsesDatabaseCollector(), '/project', $trackTranslations);
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
