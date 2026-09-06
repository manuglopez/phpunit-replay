<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\Errored;
use PHPUnit\Event\Test\ErroredSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnErrored.php
 */
final readonly class RecordErrored implements ErroredSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(Errored $event): void
    {
        $this->collector->testErrored($event->throwable()->message());
    }
}
