<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\Failed;
use PHPUnit\Event\Test\FailedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnFailed.php
 */
final readonly class RecordFailed implements FailedSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(Failed $event): void
    {
        $this->collector->testFailed($event->throwable()->message());
    }
}
