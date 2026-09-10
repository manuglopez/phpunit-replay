<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Attributes\NotCacheable;
use Manuglopez\Replay\PHPUnit\TestMethodFile;
use Manuglopez\Replay\Record\NotCacheableCollector;
use Manuglopez\Replay\Support\Paths;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use ReflectionClass;
use Throwable;

/**
 * Records which currently-preparing test carries a `#[NotCacheable]` attribute
 * (SPEC.md §8 rule 1): a class-level attribute marks the whole test file (recorded as
 * its project-relative path), a method-level one marks just that `Class::method` id.
 * Registered for every mode that collects results (wrapper and in-process alike),
 * alongside `CollectResultOnPreparationStarted`.
 */
final readonly class RecordNotCacheableOnPreparationStarted implements PreparationStartedSubscriber
{
    public function __construct(
        private NotCacheableCollector $collector,
        private string $projectRoot,
    ) {
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        try {
            $class = new ReflectionClass($test->className());
        } catch (Throwable) {
            return;
        }

        if ($class->getAttributes(NotCacheable::class) !== []) {
            // The running class's file (TestMethodFile::of()), not $test->file(): this
            // marks a FILE not-cacheable, and the file a future pass checks that marker
            // against (Cache\Graph::isNotCacheable(), Select\RunListBuilder::build()) is
            // always the concrete class that runs, never an abstract base it inherited the
            // attribute from — reflection does not see an inherited class attribute in the
            // first place ($class here is already the concrete class), so this only
            // matters when the attribute sits on the concrete class itself.
            $rel = Paths::relative($this->projectRoot, TestMethodFile::of($test));

            if ($rel !== null) {
                $this->collector->add($rel);
            }

            return;
        }

        if (! $class->hasMethod($test->methodName())) {
            return;
        }

        try {
            $method = $class->getMethod($test->methodName());
        } catch (Throwable) {
            return;
        }

        if ($method->getAttributes(NotCacheable::class) !== []) {
            $this->collector->add($test->className() . '::' . $test->methodName());
        }
    }
}
