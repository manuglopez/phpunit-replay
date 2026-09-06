<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\Skipped;
use PHPUnit\Event\Test\SkippedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnSkipped.php
 */
final readonly class RecordSkipped implements SkippedSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(Skipped $event): void
    {
        $this->collector->testSkipped($event->message());
    }
}
