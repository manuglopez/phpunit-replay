<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\PhpDeprecationTriggered;
use PHPUnit\Event\Test\PhpDeprecationTriggeredSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnPhpDeprecationTriggered.php
 */
final readonly class RecordPhpDeprecationTriggered implements PhpDeprecationTriggeredSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(PhpDeprecationTriggered $event): void
    {
        if ($event->wasSuppressed()) {
            return;
        }

        $this->collector->testTriggeredDeprecation($event->message());
    }
}
