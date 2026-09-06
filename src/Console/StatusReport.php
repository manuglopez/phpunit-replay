<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console;

use Manuglopez\Replay\Report\Format;

/**
 * Renders `phpunit-replay status` (SPEC.md §11): project root, git identity, state
 * directory, coverage driver, framework auto-detection and, once a baseline exists,
 * graph statistics, per-branch results and fingerprint drift markers.
 *
 * @phpstan-type BranchInfo array{sha: ?string, complete: bool, results: int}
 */
final readonly class StatusReport
{
    /**
     * @param array<string, BranchInfo> $branches
     * @param array<string, mixed>|null $graphFingerprint
     * @param array<string, mixed> $currentFingerprint
     */
    public function __construct(
        public string $root,
        public ?string $branch,
        public string $defaultBranch,
        public ?string $headSha,
        public string $stateDir,
        public string $driverLine,
        public string $framework,
        public bool $hasBaseline,
        public array $branches,
        public int $files,
        public int $testFiles,
        public int $edges,
        public int $graphBytes,
        public ?array $graphFingerprint,
        public array $currentFingerprint,
        public int $quarantined,
    ) {
    }

    /** @return list<string> */
    public function lines(): array
    {
        $lines = [
            'root:      ' . $this->root,
            'branch:    ' . ($this->branch ?? 'HEAD (detached)') . ' (default: ' . $this->defaultBranch . ')',
            'head:      ' . ($this->headSha !== null ? substr($this->headSha, 0, 7) : 'unknown'),
            'state dir: ' . $this->stateDir,
            'driver:    ' . $this->driverLine,
            'framework: ' . $this->framework,
            '',
        ];

        if (! $this->hasBaseline) {
            $lines[] = 'no baseline yet';

            return $lines;
        }

        $lines[] = 'files:      ' . $this->files;
        $lines[] = 'test files: ' . $this->testFiles;
        $lines[] = 'edges:      ' . $this->edges;
        $lines[] = 'graph.json: ' . Format::bytes($this->graphBytes);
        $lines[] = '';
        $lines[] = 'results:';

        foreach ($this->branches as $branchName => $info) {
            $lines[] = sprintf(
                '  %-20s %-10s %-10s %d results',
                $branchName,
                $info['complete'] ? 'complete' : 'partial',
                $info['sha'] !== null ? substr($info['sha'], 0, 7) : '-',
                $info['results'],
            );
        }

        $lines[] = '';
        $lines[] = 'fingerprint:';
        $lines[] = '  structural:    ' . $this->fingerprintLine('structural');
        $lines[] = '  environmental: ' . $this->fingerprintLine('environmental');
        $lines[] = '';
        $lines[] = 'quarantined: ' . $this->quarantined;

        return $lines;
    }

    public function format(): string
    {
        return implode(PHP_EOL, $this->lines());
    }

    private function fingerprintLine(string $bucket): string
    {
        $stored = $this->bucket($this->graphFingerprint, $bucket);
        $current = $this->bucket($this->currentFingerprint, $bucket);

        $keys = array_unique([...array_keys($stored), ...array_keys($current)]);
        sort($keys);

        $parts = [];

        foreach ($keys as $key) {
            $value = $stored[$key] ?? null;
            $drift = ($current[$key] ?? null) !== $value;
            $parts[] = $key . '=' . self::scalar($value) . ($drift ? ' (drift)' : '');
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed>|null $fingerprint
     * @return array<string, mixed>
     */
    private function bucket(?array $fingerprint, string $key): array
    {
        $raw = $fingerprint[$key] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return 'unknown';
    }
}
