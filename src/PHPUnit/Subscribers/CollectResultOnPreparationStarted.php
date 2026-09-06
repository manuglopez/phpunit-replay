<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultsAreCollected.php
 */
final readonly class CollectResultOnPreparationStarted implements PreparationStartedSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->collector->testPrepared($test->id(), $test->file());
        }
    }
}
