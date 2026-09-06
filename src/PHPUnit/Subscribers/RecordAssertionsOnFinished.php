<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaAssertionsAreRecordedOnFinished.php
 */
final readonly class RecordAssertionsOnFinished implements FinishedSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(Finished $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            $this->collector->recordAssertions(
                $test->id(),
                $event->numberOfAssertionsPerformed(),
            );
        }

        $this->collector->finishTest();
    }
}
