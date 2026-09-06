<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit\Subscribers;

use Manuglopez\Replay\PHPUnit\ReplayState;
use PHPUnit\Event\Application\Finished;
use PHPUnit\Event\Application\FinishedSubscriber;

/**
 * Prints the `Replay ...` summary line below PHPUnit's own output.
 *
 * `Application\Finished`, not `TestRunner\Finished`: the latter is emitted from inside
 * `TextUI\TestRunner::run()` (vendor/phpunit/phpunit/src/TextUI/TestRunner.php:67), which
 * `TextUI\Application::run()` calls *before* `OutputFacade::printResult()`
 * (vendor/phpunit/phpunit/src/TextUI/Application.php:281). `applicationFinished()` is the
 * last event of the run (Application.php:310) and is the only one that fires after the
 * result printer.
 */
final readonly class PrintSummaryOnApplicationFinished implements FinishedSubscriber
{
    public function notify(Finished $event): void
    {
        $line = ReplayState::summaryLine();

        if ($line === null) {
            return;
        }

        fwrite(STDOUT, $line . PHP_EOL);
    }
}
