<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\Record\Recorder;
use PHPUnit\Event\Test\Finished;
use PHPUnit\Event\Test\FinishedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaEnds.php
 */
final readonly class StopRecordingOnFinished implements FinishedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(Finished $event): void
    {
        $this->recorder->endTest();
    }
}
