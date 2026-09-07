<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit\Subscribers;

use Manuglopez\Replay\PHPUnit\Subscribers\RecordRepeatOrRetryNotCacheableOnPreparationStarted;
use Manuglopez\Replay\Record\NotCacheableCollector;
use Manuglopez\Replay\Tests\Support\TelemetryFixture;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Telemetry;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\MetadataCollection;

/**
 * Exercises the subscriber against real `TestMethod` value objects (constructed
 * directly, the same way `RecordNotCacheableOnPreparationStartedTest` does), including
 * one carrying repetition/attempt suffixes in its `id()` the way PHPUnit >= 13.3 builds
 * them for `#[Repeat]`/`#[Retry]` — the whole reason this subscriber records `id()`
 * itself rather than the bare `Class::method` combination.
 */
final class RecordRepeatOrRetryNotCacheableOnPreparationStartedTest extends TestCase
{
    private function telemetryInfo(): Telemetry\Info
    {
        return TelemetryFixture::info();
    }

    private function testMethod(
        string $class,
        string $method,
        int $repetition = 1,
        int $totalRepetitions = 1,
        int $attempt = 1,
        int $maxAttempts = 1,
    ): TestMethod {
        return new TestMethod(
            $class,
            $method,
            '/project/tests/FixtureTest.php',
            10,
            new TestDox($class, $method, $method),
            MetadataCollection::fromArray([]),
            TestDataCollection::fromArray([]),
            $repetition,
            $totalRepetitions,
            $attempt,
            $maxAttempts,
        );
    }

    public function test_a_repeat_attribute_records_the_exact_suffixed_id(): void
    {
        if (! class_exists(\PHPUnit\Framework\Attributes\Repeat::class)) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (#[Repeat] does not exist before it)');
        }

        $collector = new NotCacheableCollector();
        $subscriber = new RecordRepeatOrRetryNotCacheableOnPreparationStarted($collector);

        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixtureRepeatSubject::class, 'testRepeated', repetition: 2, totalRepetitions: 3),
        ));

        self::assertSame(
            [FixtureRepeatSubject::class . '::testRepeated (repetition 2 of 3)'],
            $collector->all(),
        );
    }

    public function test_a_retry_attribute_records_the_exact_suffixed_id(): void
    {
        if (! class_exists(\PHPUnit\Framework\Attributes\Retry::class)) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (#[Retry] does not exist before it)');
        }

        $collector = new NotCacheableCollector();
        $subscriber = new RecordRepeatOrRetryNotCacheableOnPreparationStarted($collector);

        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixtureRetrySubject::class, 'testRetried', attempt: 2, maxAttempts: 3),
        ));

        self::assertSame(
            [FixtureRetrySubject::class . '::testRetried (attempt 2 of 3)'],
            $collector->all(),
        );
    }

    public function test_a_first_attempt_records_the_bare_unsuffixed_id(): void
    {
        // attempt 1/repetition 1 never suffixes id() (PHPUnit\Event\Code\TestMethod::id()),
        // yet the method is still decorated and must still be excluded from replay: a
        // later run where the test passes on its first try must not serve THIS run's
        // first-attempt result from cache either.
        if (! class_exists(\PHPUnit\Framework\Attributes\Retry::class)) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (#[Retry] does not exist before it)');
        }

        $collector = new NotCacheableCollector();
        $subscriber = new RecordRepeatOrRetryNotCacheableOnPreparationStarted($collector);

        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixtureRetrySubject::class, 'testRetried'),
        ));

        self::assertSame([FixtureRetrySubject::class . '::testRetried'], $collector->all());
    }

    public function test_a_method_carrying_both_attributes_still_records_the_id(): void
    {
        // PHPUnit\Framework\TestBuilder ignores #[Retry] when #[Repeat] is also present
        // (with a warning) rather than rejecting the method, so both attributes on one
        // method is a real, reachable case — not a hypothetical one.
        if (! class_exists(\PHPUnit\Framework\Attributes\Repeat::class) || ! class_exists(\PHPUnit\Framework\Attributes\Retry::class)) {
            self::markTestSkipped('requires PHPUnit >= 13.3 (#[Repeat]/#[Retry] do not exist before it)');
        }

        $collector = new NotCacheableCollector();
        $subscriber = new RecordRepeatOrRetryNotCacheableOnPreparationStarted($collector);

        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixtureBothSubject::class, 'testBoth', repetition: 1, totalRepetitions: 2),
        ));

        self::assertSame(
            [FixtureBothSubject::class . '::testBoth (repetition 1 of 2)'],
            $collector->all(),
        );
    }

    public function test_a_test_without_either_attribute_records_nothing(): void
    {
        $collector = new NotCacheableCollector();
        $subscriber = new RecordRepeatOrRetryNotCacheableOnPreparationStarted($collector);

        $subscriber->notify(new PreparationStarted(
            $this->telemetryInfo(),
            $this->testMethod(FixturePlainRepeatRetrySubject::class, 'testOne'),
        ));

        self::assertSame([], $collector->all());
    }

    /**
     * The mechanism this subscriber depends on — `ReflectionMethod::getAttributes($name)`
     * filtering by class name as a plain string — never requires that class to be
     * loadable: on PHPUnit 11.5/12/13.0-13.2, where
     * `PHPUnit\Framework\Attributes\Repeat`/`Retry` do not exist at all, the exact same
     * call simply returns an empty array instead of failing. Demonstrated here against a
     * deliberately made-up class name so the assertion holds regardless of which PHPUnit
     * happens to be installed while running this suite.
     */
    public function test_detection_does_not_require_the_attribute_class_to_be_loadable(): void
    {
        self::assertFalse(class_exists('Manuglopez\Replay\Tests\Fixtures\ThisClassIsNeverDefined', false));

        $method = new \ReflectionMethod(FixturePlainRepeatRetrySubject::class, 'testOne');

        self::assertSame([], $method->getAttributes('Manuglopez\Replay\Tests\Fixtures\ThisClassIsNeverDefined'));
    }
}

/**
 * Reflection subjects, deliberately NOT `TestCase` subclasses: the subscriber only needs
 * a real class name/method for `ReflectionClass` to look attributes up on, and a plain
 * class avoids these fixtures being auto-discovered as tests of their own by the
 * package's real suite (any `TestCase` subclass in a `*Test.php` file would be). They
 * carry PHPUnit's real `#[Repeat]`/`#[Retry]` attributes when the installed PHPUnit
 * supports them (>= 13.3) so the subscriber's own reflection sees genuine attributes; on
 * an older PHPUnit these fixtures simply have no such attribute to find (see
 * `test_detection_does_not_require_the_attribute_class_to_be_loadable` for how the
 * subscriber stays correct either way).
 */
if (class_exists(\PHPUnit\Framework\Attributes\Repeat::class)) {
    final class FixtureRepeatSubject
    {
        #[\PHPUnit\Framework\Attributes\Repeat(3)]
        public function testRepeated(): void
        {
        }
    }

    final class FixtureRetrySubject
    {
        #[\PHPUnit\Framework\Attributes\Retry(3)]
        public function testRetried(): void
        {
        }
    }

    final class FixtureBothSubject
    {
        #[\PHPUnit\Framework\Attributes\Repeat(2)]
        #[\PHPUnit\Framework\Attributes\Retry(2)]
        public function testBoth(): void
        {
        }
    }
} else {
    final class FixtureRepeatSubject
    {
        public function testRepeated(): void
        {
        }
    }

    final class FixtureRetrySubject
    {
        public function testRetried(): void
        {
        }
    }

    final class FixtureBothSubject
    {
        public function testBoth(): void
        {
        }
    }
}

final class FixturePlainRepeatRetrySubject
{
    public function testOne(): void
    {
    }
}
