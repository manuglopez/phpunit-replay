<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\WarningTriggered;
use PHPUnit\Event\Test\WarningTriggeredSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnWarningTriggered.php
 */
final readonly class RecordWarningTriggered implements WarningTriggeredSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(WarningTriggered $event): void
    {
        if ($event->wasSuppressed()) {
            return;
        }

        $this->collector->testTriggeredWarning($event->message());
    }
}
