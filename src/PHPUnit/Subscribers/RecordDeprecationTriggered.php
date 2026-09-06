<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\DeprecationTriggered;
use PHPUnit\Event\Test\DeprecationTriggeredSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnDeprecationTriggered.php
 */
final readonly class RecordDeprecationTriggered implements DeprecationTriggeredSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(DeprecationTriggered $event): void
    {
        if ($event->wasSuppressed()) {
            return;
        }

        $this->collector->testTriggeredDeprecation($event->message());
    }
}
