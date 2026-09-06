<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\ConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnRisky.php
 */
final readonly class RecordConsideredRisky implements ConsideredRiskySubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(ConsideredRisky $event): void
    {
        $this->collector->testRisky($event->message());
    }
}
