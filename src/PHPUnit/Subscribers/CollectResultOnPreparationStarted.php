<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\PHPUnit\TestMethodFile;
use Manuglopez\Replay\Record\ResultCollector;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Subscribers/EnsureTiaResultsAreCollected.php
 */
final readonly class CollectResultOnPreparationStarted implements PreparationStartedSubscriber
{
    public function __construct(private ResultCollector $collector)
    {
    }

    public function notify(PreparationStarted $event): void
    {
        $test = $event->test();

        if ($test instanceof TestMethod) {
            // The result's stored `file` feeds Cache\GraphUpdater::mergeResults(), which
            // computes and attaches this test's content key from THAT file's dependency
            // list (Cache\ContentKey::forTestFile()) — it must be the running class's file
            // (TestMethodFile::of()), the same one StartRecordingOnPreparationStarted just
            // opened edges under, or a result would carry a key computed from a different
            // file's dependencies than the ones actually recorded for it.
            $this->collector->testPrepared($test->id(), TestMethodFile::of($test));
        }
    }
}
