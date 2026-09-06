<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\PhpNoticeTriggered;
use PHPUnit\Event\Test\PhpNoticeTriggeredSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnPhpNoticeTriggered.php
 */
final readonly class RecordPhpNoticeTriggered implements PhpNoticeTriggeredSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(PhpNoticeTriggered $event): void
    {
        if ($event->wasSuppressed()) {
            return;
        }

        $this->collector->testTriggeredNotice($event->message());
    }
}
