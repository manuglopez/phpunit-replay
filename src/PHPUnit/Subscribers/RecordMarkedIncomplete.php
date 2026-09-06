<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\MarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnIncomplete.php
 */
final readonly class RecordMarkedIncomplete implements MarkedIncompleteSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(MarkedIncomplete $event): void
    {
        $this->collector->testIncomplete($event->throwable()->message());
    }
}
