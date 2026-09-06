<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Test\Passed;
use PHPUnit\Event\Test\PassedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultIsRecordedOnPassed.php
 */
final readonly class RecordPassed implements PassedSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(Passed $event): void
    {
        $this->collector->testPassed();
    }
}
