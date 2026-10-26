<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Illuminate\Container\Container;
use Illuminate\Translation\Translator;
use Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPreparationStarted;
use Manuglopez\Replay\Laravel\Subscribers\ArmLaravelTrackersOnPrepared;
use Manuglopez\Replay\Laravel\UsesDatabaseCollector;
use Manuglopez\Replay\Record\Recorder;
use Manuglopez\Replay\Tests\Support\TelemetryFixture;
use Manuglopez\Replay\Tests\Unit\Laravel\Fixtures\FakeTranslatorApp;
use Manuglopez\Replay\Tests\Unit\Record\FakeCoverageDriver;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\MetadataCollection;

/**
 * The subscriber finds the running test case on the call stack (that is this very test), so
 * the test class plays the part of Laravel's `InteractsWithTestCaseLifecycle`: it offers
 * `afterApplicationCreated()` and `beforeApplicationDestroyed()` and keeps the callbacks.
 */
final class ArmLaravelTrackersOnPreparationStartedTranslationsTest extends TestCase
{
    private const TEST_FILE = '/project/tests/FooTest.php';

    /** @var list<\Closure> */
    private array $created = [];

    /** @var list<\Closure> */
    private array $destroying = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/Fixtures/IlluminateTranslatorStub.php';
        require_once __DIR__ . '/Fixtures/IlluminateContainerStub.php';
    }

    public function afterApplicationCreated(\Closure $callback): void
    {
        $this->created[] = $callback;
    }

    public function beforeApplicationDestroyed(\Closure $callback): void
    {
        $this->destroying[] = $callback;
    }

    #[Group('AC-I1-e')]
    public function test_per_key_registers_the_end_of_test_check_that_catches_a_swapped_translator(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([[]]));
        $recorder->beginTest(self::TEST_FILE);
        $app = new FakeTranslatorApp(static fn (): Translator => new Translator(new \stdClass(), 'en'));

        $this->notify(new ArmLaravelTrackersOnPrepared($recorder, new UsesDatabaseCollector(), '/project', true));

        self::assertCount(1, $this->created, 'the late arming fallback');
        self::assertCount(1, $this->destroying, 'the end-of-test check');

        // The registered check looks at the global container: here, one whose translator was swapped.
        $arming = new ArmLaravelTrackersOnPrepared($recorder, new UsesDatabaseCollector(), '/project', true);
        $arming->armTrackers($app);
        $app->swap('translator', new \stdClass());
        Container::$instance = $app;

        try {
            ($this->destroying[0])();
        } finally {
            Container::$instance = null;
        }

        $recorder->endTest();
        self::assertSame([self::TEST_FILE => ['*']], $recorder->perTestTranslations());
    }

    #[Group('AC-I1-d')]
    public function test_conservative_registers_no_end_of_test_check(): void
    {
        $recorder = new Recorder(new FakeCoverageDriver([[]]));

        $this->notify(new ArmLaravelTrackersOnPrepared($recorder, new UsesDatabaseCollector(), '/project', false));

        self::assertCount(1, $this->created);
        self::assertSame([], $this->destroying);
    }

    private function notify(ArmLaravelTrackersOnPrepared $arming): void
    {
        (new ArmLaravelTrackersOnPreparationStarted($arming))->notify(new PreparationStarted(
            TelemetryFixture::info(),
            new TestMethod(
                self::class,
                'test_x',
                __FILE__,
                10,
                new TestDox(self::class, 'test_x', 'test x'),
                MetadataCollection::fromArray([]),
                TestDataCollection::fromArray([]),
            ),
        ));
    }
}
