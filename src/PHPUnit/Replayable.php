<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use Manuglopez\Replay\PHPUnit\Decision\Decision;
use Manuglopez\Replay\PHPUnit\Decision\ReplayIncomplete;
use Manuglopez\Replay\PHPUnit\Decision\ReplayPass;
use Manuglopez\Replay\PHPUnit\Decision\ReplaySkipped;
use Manuglopez\Replay\PHPUnit\Decision\Run;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

/**
 * Add to the project's base `TestCase` to enable in-process replay (SPEC.md §3.2, §6.3):
 *
 *     abstract class TestCase extends \PHPUnit\Framework\TestCase
 *     {
 *         use \Manuglopez\Replay\PHPUnit\Replayable;
 *
 *         protected function setUp(): void
 *         {
 *             parent::setUp();
 *             if ($this->isReplaying()) { return; }   // skip the expensive boot
 *             // ...
 *         }
 *     }
 *
 * Two mechanisms, per docs/spikes/in-process-replay.md:
 *  - PHPUnit >= 12 declares `protected function invokeTestMethod()`, called from the
 *    private `runTest()`. Overriding it is the clean hook: `setUp()` and the
 *    preconditions run untouched and always see the real method name.
 *  - PHPUnit 11.5 has no such hook, so a `#[Before]` method swaps the private
 *    `TestCase::$methodName` to `__replayStub` through reflection; the stub restores the
 *    name before doing anything else. Forced by `PHPUNIT_REPLAY_LEGACY_HOOK=1`, which is
 *    how the package's own tests exercise this path on PHPUnit 12.
 *
 * When `ReplayState` is not driving an in-process run (no extension, or the wrapper's
 * filtered mode, or replay disabled) every hook falls through to PHPUnit unchanged.
 *
 * @mixin TestCase
 */
trait Replayable
{
    private static ?bool $__replayLegacyHook = null;

    private ?Decision $__replayDecision = null;

    private ?string $__replayMethodName = null;

    /** True when this test's body will not run: its result comes from the baseline. */
    public function isReplaying(): bool
    {
        return $this->__replayDecision()->isReplay();
    }

    /**
     * @param  array<mixed>  $testArguments
     *
     * @throws Throwable
     */
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (! self::__replayUsesLegacyHook()) {
            $decision = $this->__replayDecision();

            if ($decision->isReplay()) {
                return $this->__replayApply($decision);
            }
        }

        return $this->__replayInvokeParentTestMethod($methodName, $testArguments);
    }

    /**
     * `invokeTestMethod()` only exists on `TestCase` since PHPUnit >= 12 (`runTest()` calls
     * it there); PHPUnit 11.5's `TestCase` never declares it, and its `runTest()` never calls
     * this override in the first place (the 11.5 path replays through the `#[Before]`
     * method-name swap below instead). Neither a literal `parent::invokeTestMethod(...)` nor
     * `parent::{$hook}(...)`/`method_exists(TestCase::class, $hook)` with `$hook` held in a
     * local variable dodges PHPStan here: it infers a constant-string type for such a
     * variable and resolves the call, or narrows the existence check, exactly as it would
     * for a literal — "always true" against the PHPUnit 12 vendor, "undefined method" against
     * 11.5. `ReflectionClass::hasMethod()` is not one of the functions PHPStan's
     * `function.alreadyNarrowedType` rule special-cases (only the `*_exists()`/`is_callable()`
     * builtins are), and going through `ReflectionMethod::invoke()` to call it sidesteps
     * static call resolution entirely (PHPStan cannot know which method a `ReflectionMethod`
     * instance wraps) — so nothing here is resolved against a specific PHPUnit version. The
     * fallback below is dead code under normal operation (this method is never entered on
     * 11.5) but keeps the class internally consistent instead of assuming that caller graph.
     *
     * @param  array<mixed>  $testArguments
     *
     * @throws Throwable
     */
    private function __replayInvokeParentTestMethod(string $methodName, array $testArguments): mixed
    {
        $hook = 'invokeTestMethod';

        if ((new ReflectionClass(TestCase::class))->hasMethod($hook)) {
            return (new ReflectionMethod(TestCase::class, $hook))->invoke($this, $methodName, $testArguments);
        }

        return $this->{$methodName}(...$testArguments);
    }

    /**
     * PHPUnit 11.5 path. `#[Before]` runs before `setUp()`, so the swapped name is in
     * place well before the private `runTest()` resolves `$this->methodName`.
     */
    #[Before]
    public function __replayBeforeTest(): void
    {
        if (! self::__replayUsesLegacyHook() || ! $this->__replayDecision()->isReplay()) {
            return;
        }

        try {
            $property = new ReflectionProperty(TestCase::class, 'methodName');
            $current = $property->getValue($this);

            if (! is_string($current)) {
                return;
            }

            $this->__replayMethodName = $current;
            $property->setValue($this, '__replayStub');
        } catch (Throwable) {
            $this->__replayMethodName = null;
        }
    }

    /** Stand-in for the real test method under the PHPUnit 11.5 hook. Never a test itself. */
    public function __replayStub(mixed ...$arguments): mixed
    {
        $this->__replayRestoreMethodName();

        return $this->__replayApply($this->__replayDecision());
    }

    private function __replayApply(Decision $decision): mixed
    {
        $id = $this->valueObjectForEvents()->id();

        if ($decision instanceof ReplayPass) {
            // A test recorded as risky had no assertions and no expectation of having
            // none; replaying it the same way keeps it risky, exactly as recorded.
            if ($decision->assertions === 0 && ! $decision->wasRisky) {
                $this->expectNotToPerformAssertions();
            }

            // max(): a corrupted cache must never hand PHPUnit a negative count.
            $this->addToAssertionCount(max(0, $decision->assertions));
            ReplayState::markReplayed($id, $decision);

            return null;
        }

        if ($decision instanceof ReplaySkipped) {
            ReplayState::markReplayed($id, $decision);
            $this->markTestSkipped($decision->message);
        }

        if ($decision instanceof ReplayIncomplete) {
            ReplayState::markReplayed($id, $decision);
            $this->markTestIncomplete($decision->message);
        }

        return null;
    }

    private function __replayDecision(): Decision
    {
        if ($this->__replayDecision !== null) {
            return $this->__replayDecision;
        }

        if (! ReplayState::isInProcess()) {
            return $this->__replayDecision = new Run('no-baseline');
        }

        $file = (new ReflectionClass(static::class))->getFileName();

        return $this->__replayDecision = ReplayState::decide(
            $file === false ? '' : $file,
            $this->valueObjectForEvents()->id(),
        );
    }

    private function __replayRestoreMethodName(): void
    {
        if ($this->__replayMethodName === null) {
            return;
        }

        try {
            (new ReflectionProperty(TestCase::class, 'methodName'))->setValue($this, $this->__replayMethodName);
        } catch (Throwable) {
            // Nothing to restore to: reporting already snapshotted the real name.
        }

        $this->__replayMethodName = null;
    }

    /**
     * PHPUnit 11.5 has no `invokeTestMethod()` hook, so the reflection swap is the only
     * way in there; `PHPUNIT_REPLAY_LEGACY_HOOK=1` forces the same path on PHPUnit 12 so
     * the package's own tests cover it. Asked through reflection rather than
     * `method_exists()` so it stays a real runtime question on every PHPUnit version.
     */
    private static function __replayUsesLegacyHook(): bool
    {
        if (self::$__replayLegacyHook !== null) {
            return self::$__replayLegacyHook;
        }

        if (getenv('PHPUNIT_REPLAY_LEGACY_HOOK') === '1') {
            return self::$__replayLegacyHook = true;
        }

        try {
            return self::$__replayLegacyHook = ! (new ReflectionClass(TestCase::class))->hasMethod('invokeTestMethod');
        } catch (Throwable) {
            return self::$__replayLegacyHook = false;
        }
    }
}
