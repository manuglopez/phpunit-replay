<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\PHPUnit;

use Manuglopez\Replay\PHPUnit\TestMethodFile;
use PHPUnit\Event\Code\TestDox;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\TestData\TestDataCollection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Metadata\MetadataCollection;

/**
 * Exercises `TestMethodFile::of()` directly against real `TestMethod` value objects
 * (constructed the same way `SubscribersTest` does), rather than through any one
 * subscriber — `tests/Integration/AbstractBaseTestClassEdgesTest.php` covers the
 * end-to-end recording behaviour through a real PHPUnit run.
 */
final class TestMethodFileTest extends TestCase
{
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

    /**
     * The overwhelmingly common case: an ordinary test class declares its own method, so
     * `TestMethod::file()` (the declaring file PHPUnit itself resolved) and the running
     * class's OWN reflected file are the same file. Must be a strict no-op.
     */
    public function test_an_ordinary_class_resolves_to_its_own_file(): void
    {
        $test = $this->testMethod(ConcreteFixtureSubject::class, 'testOne', __FILE__);

        self::assertSame(__FILE__, TestMethodFile::of($test));
    }

    /**
     * The defect this class exists to fix: `ConcreteSubclassFixtureSubject` declares no
     * method of its own, so PHPUnit's own `TestMethod::file()` would resolve to
     * `AbstractBaseFixtureSubject`'s file for `testInherited` — simulated here by
     * constructing the `TestMethod` with a `$file` argument that is NOT where the
     * concrete class lives. `TestMethodFile::of()` must resolve to the concrete class's
     * OWN file (this one) regardless of what `$file` says.
     */
    public function test_an_inherited_method_resolves_to_the_running_concrete_classs_file(): void
    {
        $test = $this->testMethod(
            ConcreteSubclassFixtureSubject::class,
            'testInherited',
            '/nonexistent/declaring-file.php',
        );

        self::assertSame(__FILE__, TestMethodFile::of($test));
    }

    /**
     * `className()` is always `$testCase::class` (a real, already-instantiated test
     * object), so this cannot happen in ordinary operation — but a caller that has not
     * decided must not crash either: it falls back to `$test->file()`, the pre-fix
     * behaviour, exactly as `RecordNotCacheableOnPreparationStarted`'s own
     * `new ReflectionClass($test->className())` already tolerates elsewhere in this
     * codebase.
     */
    public function test_a_class_that_cannot_be_reflected_falls_back_to_the_declaring_file(): void
    {
        $test = $this->testMethod('Manuglopez\\Replay\\Tests\\NoSuchClassAtAll', 'testOne', '/some/declared/file.php');

        self::assertSame('/some/declared/file.php', TestMethodFile::of($test));
    }

    /**
     * `$test->file()` on a real `PHPUnit\Event\Code\TestMethod` is guaranteed a
     * non-empty-string (its own docblock), never null or false — same for the resolved
     * value here, whichever branch produced it.
     */
    public function test_the_result_is_always_a_non_empty_string(): void
    {
        self::assertNotSame('', TestMethodFile::of($this->testMethod(ConcreteFixtureSubject::class, 'testOne', __FILE__)));
        self::assertNotSame(
            '',
            TestMethodFile::of($this->testMethod('Manuglopez\\Replay\\Tests\\NoSuchClassAtAll', 'testOne', '/x.php')),
        );
    }
}

/** Declared in this very file: its own reflected file IS __FILE__, matching TestMethod::file(). */
final class ConcreteFixtureSubject
{
    public function testOne(): void
    {
    }
}

abstract class AbstractBaseFixtureSubject
{
    public function testInherited(): void
    {
    }
}

/** Adds no method of its own: `testInherited` is declared in `AbstractBaseFixtureSubject`. */
final class ConcreteSubclassFixtureSubject extends AbstractBaseFixtureSubject
{
}
