<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\NotCacheableCollector;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use ReflectionClass;
use Throwable;

/**
 * Records the currently-preparing test's exact id as not-cacheable when its method
 * carries a `#[Repeat]` or `#[Retry]` attribute (`PHPUnit\Framework\Attributes\Repeat`/
 * `Retry`, PHPUnit >= 13.3 only). Both attributes are `Attribute::TARGET_METHOD`-only
 * (verified against `vendor/phpunit/phpunit/src/Framework/Attributes/{Repeat,Retry}.php`),
 * so — unlike `RecordNotCacheableOnPreparationStarted` — there is no class-level case to
 * mirror.
 *
 * `PHPUnit\Framework\TestBuilder` (13.3+) reads these attributes regardless of any
 * `--repeat`/`--retry` CLI option (a method-level `#[Repeat]` takes precedence over
 * `--retry`, per its own comments), so a decorated method repeats/retries on *every* run,
 * with or without a flag on the command line — `ConfigurationReader::repeatOrRetryRequested()`
 * (the CLI-flag guard `RunPipeline`/`ReplayExtension::bootstrapInProcess()` both check)
 * never fires for it. From that version on, `PHPUnit\Event\Code\TestMethod::id()` appends
 * `' (repetition %d of %d)'`/`' (attempt %d of %d)'` once `totalRepetitions`/`attempt`
 * exceed 1 — the exact string this package keys recorded results on and looks up replay
 * decisions by (`ReplayState::decide()`, `Hermeticity\Policy::cacheable()` via
 * `Cache\Graph::isNotCacheable()`, which matches an id only exactly, never by prefix). A
 * `#[Repeat(3)]` method produces the *same* three suffixed ids on every run (the
 * attribute, unlike a CLI flag, cannot differ run to run), so without this subscriber a
 * recording run's cached repetitions would silently replay on the next run instead of
 * actually re-executing — defeating the one thing `#[Repeat]`/`#[Retry]` exist for.
 *
 * This subscriber therefore records `TestMethod::id()` itself (not the bare
 * `Class::method` id `RecordNotCacheableOnPreparationStarted` uses for a method-level
 * `#[NotCacheable]`): it fires once per repetition/attempt PHPUnit actually prepares, so
 * whatever id is current at that moment — including the bare, unsuffixed one a first
 * repetition/attempt always gets — is exactly what lands in the `NotCacheableCollector`
 * shared with `RecordNotCacheableOnPreparationStarted`, and therefore in the graph's
 * `not_cacheable` section (`GraphUpdater::applyNotCacheable()`), the same way a
 * `#[NotCacheable]` entry does: excluded from replay (`Hermeticity\Policy::cacheable()`)
 * and, since a decorated method always executes, never short-circuited by a cached hit.
 *
 * Detection is plain reflection matching the attribute's class name as a literal string
 * (`ReflectionMethod::getAttributes($name)`), not
 * `PHPUnit\Metadata\Parser\Registry::parser()->forMethod(...)->isRepeat()`/`isRetry()`:
 * those `MetadataCollection` accessors — like the attribute classes themselves — do not
 * exist before PHPUnit 13.3, which would need the same non-literal dynamic-dispatch
 * device `ConfigurationReader::testSuiteNames()`/`intOption()` use to keep PHPStan from
 * resolving a version-specific method call. `getAttributes()` filters by name without
 * ever needing the named class to be loadable (attribute declarations are not
 * instantiated unless `newInstance()` is called), so this needs no version gating, no
 * `class_exists()` check and no PHPStan device at all — it is simply a no-op (empty
 * result) on PHPUnit 11.5/12/13.0-13.2, where neither attribute exists.
 */
final readonly class RecordRepeatOrRetryNotCacheableOnPreparationStarted implements PreparationStartedSubscriber
{
    private const REPEAT_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Repeat';

    private const RETRY_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Retry';

    public function __construct(private NotCacheableCollector $collector)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        if ($this->methodCarriesRepeatOrRetry($test)) {
            $this->collector->add($test->id());
        }
    }

    /**
     * Takes the whole `TestMethod` value object, not its `className()`/`methodName()`
     * pulled apart into plain `string` parameters: `className()` carries a `@return
     * class-string` docblock `ReflectionClass`'s constructor needs, which a separate
     * method declared with a widened `string $className` parameter would discard.
     */
    private function methodCarriesRepeatOrRetry(TestMethod $test): bool
    {
        try {
            $class = new ReflectionClass($test->className());
        } catch (Throwable) {
            return false;
        }

        if (! $class->hasMethod($test->methodName())) {
            return false;
        }

        try {
            $method = $class->getMethod($test->methodName());
        } catch (Throwable) {
            return false;
        }

        return $method->getAttributes(self::REPEAT_ATTRIBUTE) !== []
            || $method->getAttributes(self::RETRY_ATTRIBUTE) !== [];
    }
}
