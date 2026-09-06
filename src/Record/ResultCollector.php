<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Record;

use PHPUnit\Framework\TestStatus\TestStatus;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/ResultCollector.php
 *
 * SPEC §5.4 precedence: error > failure > warning > risky > deprecation > notice > incomplete > skipped > success,
 * mirrored by `PHPUnit\Framework\TestStatus\TestStatus::asInt()`.
 *
 * @phpstan-type TestResultArray array{status: int, message: string, time: float, assertions: int, file?: string}
 */
final class ResultCollector
{
    /** @var array<string, TestResultArray> */
    private array $results = [];

    /** @var array<string, true> */
    private array $triggered = [];

    private ?string $currentTestId = null;

    private ?string $currentTestFile = null;

    private ?float $startTime = null;

    public function testPrepared(string $testId, ?string $testFile = null): void
    {
        $this->currentTestId = $testId;
        $this->currentTestFile = $testFile;
        $this->startTime = microtime(true);
    }

    public function testPassed(): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        if (isset($this->triggered[$this->currentTestId])) {
            $this->refreshTime();

            return;
        }

        $this->record(TestStatus::success());
    }

    public function testFailed(string $message): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $this->record(TestStatus::failure($message));
    }

    public function testErrored(string $message): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $this->record(TestStatus::error($message));
    }

    public function testSkipped(string $message): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $this->record(TestStatus::skipped($message));
    }

    public function testIncomplete(string $message): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $this->record(TestStatus::incomplete($message));
    }

    public function testRisky(string $message): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $this->record(TestStatus::risky($message));
    }

    public function testTriggeredWarning(string $message): void
    {
        $this->recordIssue(TestStatus::warning($message));
    }

    public function testTriggeredNotice(string $message): void
    {
        $this->recordIssue(TestStatus::notice($message));
    }

    public function testTriggeredDeprecation(string $message): void
    {
        $this->recordIssue(TestStatus::deprecation($message));
    }

    public function recordAssertions(string $testId, int $assertions): void
    {
        if (isset($this->results[$testId])) {
            $this->results[$testId]['assertions'] = $assertions;
        }
    }

    public function finishTest(): void
    {
        $this->currentTestId = null;
        $this->currentTestFile = null;
        $this->startTime = null;
    }

    public function hasUnfinishedTest(): bool
    {
        return $this->currentTestId !== null && ! isset($this->results[$this->currentTestId]);
    }

    /** @return array<string, TestResultArray> (file = absolute as given) */
    public function all(): array
    {
        return $this->results;
    }

    public function reset(): void
    {
        $this->results = [];
        $this->triggered = [];
        $this->currentTestId = null;
        $this->currentTestFile = null;
        $this->startTime = null;
    }

    private function recordIssue(TestStatus $status): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $existing = $this->results[$this->currentTestId]['status'] ?? null;

        if (is_int($existing) && $existing >= $status->asInt()) {
            return;
        }

        $this->triggered[$this->currentTestId] = true;

        $this->record($status);
    }

    private function refreshTime(): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        if (! isset($this->results[$this->currentTestId])) {
            return;
        }

        if ($this->startTime === null) {
            return;
        }

        $this->results[$this->currentTestId]['time'] = round(microtime(true) - $this->startTime, 3);
    }

    private function record(TestStatus $status): void
    {
        if ($this->currentTestId === null) {
            return;
        }

        $time = $this->startTime !== null
            ? round(microtime(true) - $this->startTime, 3)
            : 0.0;

        $existing = $this->results[$this->currentTestId] ?? null;

        $this->results[$this->currentTestId] = [
            'status' => $status->asInt(),
            'message' => $status->message(),
            'time' => $time,
            'assertions' => $existing['assertions'] ?? 0,
        ];

        if ($this->currentTestFile !== null) {
            $this->results[$this->currentTestId]['file'] = $this->currentTestFile;
        }
    }
}
