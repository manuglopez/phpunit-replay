<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use Closure;
use Manuglopez\Replay\Coverage\LineHits;
use PHPUnit\Runner\CodeCoverage as PhpUnitCodeCoverage;
use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * SPEC.md §2.4 / §3.2 last paragraph: raw pcov/xdebug recording and PHPUnit's own code
 * coverage collection clash — both want to drive the same extension's start/stop cycle
 * (`--no-coverage` exists specifically to keep the two apart). When the user asked PHPUnit
 * for a `--coverage-*` report, `ReplayExtension` hands the {@see Recorder} this driver
 * instead of the raw {@see PcovDriver}/{@see XdebugDriver}: it never touches pcov/xdebug
 * itself, only reads what PHPUnit's own `SebastianBergmann\CodeCoverage\CodeCoverage`
 * already collected for the test that just finished (the accessor is the same one
 * `Pest\Plugins\Tia\CoverageCollector` uses, @see CoverageSnapshots for the full note).
 *
 * `PHPUnit\Framework\TestRunner::run()` (vendor/phpunit/phpunit/src/Framework/TestRunner/
 * TestRunner.php:91,158) calls `CodeCoverage::instance()->start($test)`/`->stop(...)`
 * synchronously around `$test->runBare()`, both before the `Test\PreparationStarted` and
 * `Test\Finished` events this package's own {@see Recorder} reacts to are emitted — so by
 * the time `start()`/`stop()` below run, PHPUnit has already appended (or, for a risky/
 * incomplete/skipped test, deliberately not appended — a documented gap, see `stop()`) the
 * current test's own contribution under its own test id.
 */
final class PiggybackCoverageDriver implements CoverageDriver
{
    /** @var list<string> */
    private array $knownTestIds = [];

    /**
     * @param (Closure(): ?CodeCoverage)|null $coverageAccessor test seam: defaults to
     *        `PHPUnit\Runner\CodeCoverage::instance()`'s own coverage object.
     */
    public function __construct(
        private readonly SourceScope $scope,
        private readonly ?Closure $coverageAccessor = null,
    ) {
    }

    /**
     * Whether PHPUnit's own coverage collection is active *right now*. `ReplayExtension`
     * does not actually rely on this for the piggyback-vs-raw-driver decision (bootstrap()
     * runs before `PHPUnit\Runner\CodeCoverage::init()` does, so it is never true yet at
     * that point — vendor/phpunit/phpunit/src/TextUI/Application.php:154 bootstraps
     * extensions strictly before line 206's `CodeCoverage::instance()->init(...)`; the
     * extension instead reads `Configuration::hasCoverageReport()`, already known at
     * bootstrap time). This still has to exist to satisfy the `CoverageDriver` contract,
     * and is exercised directly by tests and by {@see CoverageSnapshots}'s own callers.
     */
    public static function available(): bool
    {
        return PhpUnitCodeCoverage::instance()->isActive();
    }

    public function name(): string
    {
        return 'piggyback';
    }

    /** No-op: PHPUnit itself drives its own coverage collector's start/stop. */
    public function start(): void
    {
        $coverage = $this->coverage();
        $this->knownTestIds = $coverage !== null ? array_keys($coverage->getTests()) : [];
    }

    /** @return array<string, array<int, int>> */
    public function stop(): array
    {
        $coverage = $this->coverage();
        $before = $this->knownTestIds;
        $this->knownTestIds = [];

        if ($coverage === null) {
            return [];
        }

        // Exactly one test runs between start() and stop() (Recorder's beginTest()/endTest()
        // are 1:1 with a single PHPUnit test), so at most one new key can have appeared in
        // getTests() since start() — the current test's id. None appears at all when PHPUnit
        // itself chose not to append this test's coverage (risky/incomplete/skipped tests,
        // TestRunner.php:145 `$append = !$risky && !$incomplete && !$skipped;`): a documented
        // gap of the piggyback approach the raw pcov/xdebug drivers do not have, since they
        // capture whatever executed regardless of the test's eventual status.
        $newIds = array_values(array_diff(array_keys($coverage->getTests()), $before));

        if ($newIds === []) {
            return [];
        }

        return $this->linesTouchedBy($coverage, $newIds);
    }

    /**
     * `ProcessedCodeCoverageData::lineCoverage()` is typed loosely and validated at runtime
     * rather than trusted to match `array<string, array<int, list<string>|null>>`: on the
     * php-code-coverage version paired with PHPUnit 11.5 the method carries no generic
     * return annotation at all, and from 14.3 on the per-line lists of test ids are not even
     * the shape any more. {@see \Manuglopez\Replay\Coverage\LineHits} owns that reading:
     * asking it whether a line was hit, instead of scanning for id strings that 14.3 no
     * longer puts there, is what keeps this driver — and therefore the whole dependency graph
     * of any `--coverage-*` run — from silently recording zero edges.
     *
     * @param list<string> $testIds
     * @return array<string, array<int, int>>
     */
    private function linesTouchedBy(CodeCoverage $coverage, array $testIds): array
    {
        $wanted = array_fill_keys($testIds, true);
        $data = $coverage->getData(true);
        $index = LineHits::testIds($data);
        $out = [];

        foreach ($data->lineCoverage() as $file => $lines) {
            if (! is_string($file) || ! is_array($lines) || ! $this->scope->contains($file)) {
                continue;
            }

            $hits = [];

            foreach ($lines as $line => $hit) {
                if (! is_int($line) || ! is_array($hit)) {
                    continue;
                }

                if (LineHits::hitByAny($hit, $index, $wanted)) {
                    $hits[$line] = 1;
                }
            }

            if ($hits !== []) {
                $out[$file] = $hits;
            }
        }

        return $out;
    }

    private function coverage(): ?CodeCoverage
    {
        if ($this->coverageAccessor !== null) {
            return ($this->coverageAccessor)();
        }

        return PhpUnitCodeCoverage::instance()->isActive() ? PhpUnitCodeCoverage::instance()->codeCoverage() : null;
    }
}
