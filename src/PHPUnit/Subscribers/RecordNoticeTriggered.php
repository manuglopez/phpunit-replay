<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\NoticeTriggered;
use PHPUnit\Event\Test\NoticeTriggeredSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnNoticeTriggered.php
 */
final readonly class RecordNoticeTriggered implements NoticeTriggeredSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(NoticeTriggered $event): void
    {
        if ($event->wasSuppressed()) {
            return;
        }

        $this->collector->testTriggeredNotice($event->message());
    }
}
