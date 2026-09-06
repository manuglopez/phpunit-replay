<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\PHPUnit\ReplayState;
use Manuglopez\Replay\Record\Recorder;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaStarts.php
 */
final readonly class StartRecordingOnPreparationStarted implements PreparationStartedSubscriber
{
    public function __construct(private Recorder $recorder)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if (! $test instanceof TestMethod) {
            return;
        }

        // A replayed test never executes its body, so nothing of it may end up in the
        // edges: its recorded dependencies stay exactly as the baseline has them.
        if (ReplayState::isInProcess() && ReplayState::decide($test->file(), $test->id())->isReplay()) {
            return;
        }

        $this->recorder->beginTest($test->file());
    }
}
