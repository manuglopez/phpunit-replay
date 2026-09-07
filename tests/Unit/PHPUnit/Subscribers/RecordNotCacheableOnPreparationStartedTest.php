<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit\Subscribers;

use Manuglopez\Replay\Attributes\NotCacheable;
use Manuglopez\Replay\PHPUnit\Subscribers\RecordNotCacheableOnPreparationStarted;
use Manuglopez\Replay\Record\NotCacheableCollector;
use Manuglopez\Replay\Tests\Support\TelemetryFixture;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Telemetry;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\MetadataCollection;

/**
 * Exercises the subscriber against a real `TestMethod` event (constructed directly, the
 * same way `SubscribersTest` does) so the reflection it does on the class/method it
 * names is genuine.
 */
final class RecordNotCacheableOnPreparationStartedTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('not-cacheable-subscriber');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    private function telemetryInfo(): Telemetry\Info
    {
        return TelemetryFixture::info();
    }

    private function testMethod(string $class, string $method, string $file): TestMethod
    {
        return new TestMethod(
            $class,
            $method,
            $file,
            10,
            new TestDox($class, $method, $method),
            MetadataCollection::fromArray([]),
            TestDataCollection::fromArray([]),
        );
    }

    public function test_a_class_level_attribute_records_the_test_files_relative_path(): void
    {
        $file = $this->root . '/tests/ClassLevelTest.php';
        $collector = new NotCacheableCollector();

        $subscriber = new RecordNotCacheableOnPreparationStarted($collector, $this->root);
        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixtureClassLevelNotCacheableSubject::class, 'testOne', $file),
        ));

        self::assertSame(['tests/ClassLevelTest.php'], $collector->all());
    }

    public function test_a_method_level_attribute_records_the_class_method_id(): void
    {
        $file = $this->root . '/tests/MethodLevelTest.php';
        $collector = new NotCacheableCollector();

        $subscriber = new RecordNotCacheableOnPreparationStarted($collector, $this->root);
        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixtureMethodLevelNotCacheableSubject::class, 'testMarked', $file),
        ));

        self::assertSame([FixtureMethodLevelNotCacheableSubject::class . '::testMarked'], $collector->all());
    }

    public function test_an_unmarked_method_on_a_partially_marked_class_records_nothing(): void
    {
        $file = $this->root . '/tests/MethodLevelTest.php';
        $collector = new NotCacheableCollector();

        $subscriber = new RecordNotCacheableOnPreparationStarted($collector, $this->root);
        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixtureMethodLevelNotCacheableSubject::class, 'testPlain', $file),
        ));

        self::assertSame([], $collector->all());
    }

    public function test_a_test_without_the_attribute_records_nothing(): void
    {
        $file = $this->root . '/tests/PlainTest.php';
        $collector = new NotCacheableCollector();

        $subscriber = new RecordNotCacheableOnPreparationStarted($collector, $this->root);
        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixturePlainSubject::class, 'testOne', $file),
        ));

        self::assertSame([], $collector->all());
    }
}

/**
 * Reflection subjects, deliberately NOT `TestCase` subclasses: the subscriber only
 * needs a real class name/method for `ReflectionClass` to look the attribute up on, and
 * a plain class avoids these fixtures being auto-discovered as tests of their own by the
 * package's real suite (any `TestCase` subclass in a `*Test.php` file would be).
 */
#[NotCacheable('talks to the clock')]
final class FixtureClassLevelNotCacheableSubject
{
    public function testOne(): void
    {
    }
}

final class FixtureMethodLevelNotCacheableSubject
{
    #[NotCacheable]
    public function testMarked(): void
    {
    }

    public function testPlain(): void
    {
    }
}

final class FixturePlainSubject
{
    public function testOne(): void
    {
    }
}
