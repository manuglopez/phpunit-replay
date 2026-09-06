<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Change;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Support/Git.php
 */
final readonly class Git
{
    private const TIMEOUT = 5.0;

    public function __construct(
        private ?string $directory = null,
        private float $timeout = self::TIMEOUT,
    ) {
    }

    public function withTimeout(float $timeout): self
    {
        return new self($this->directory, $timeout);
    }

    /**
     * @param list<string> $arguments
     */
    public function raw(array $arguments): ?string
    {
        $result = $this->result($arguments);

        return $result['exitCode'] === 0 ? $result['output'] : null;
    }

    /**
     * @param list<string> $arguments
     */
    public function output(array $arguments): ?string
    {
        $output = $this->raw($arguments);

        if ($output === null) {
            return null;
        }

        $output = trim($output);

        return $output === '' ? null : $output;
    }

    /**
     * @param list<string> $arguments
     */
    public function succeeds(array $arguments): bool
    {
        return $this->result($arguments)['exitCode'] === 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{exitCode: int, output: string}
     */
    public function result(array $arguments, ?string $input = null): array
    {
        $process = new Process(['git', ...$arguments], $this->directory);
        $process->setTimeout($this->timeout);

        if ($input !== null) {
            $process->setInput($input);
        }

        try {
            $process->run();
        } catch (ProcessFailedException|RuntimeException) {
            return ['exitCode' => 127, 'output' => ''];
        }

        return [
            'exitCode' => $process->getExitCode() ?? 127,
            'output' => $process->getOutput(),
        ];
    }

    public static function available(): bool
    {
        /** @var bool|null $cached */
        static $cached = null;

        if ($cached === null) {
            $cached = (new self())->succeeds(['--version']);
        }

        return $cached;
    }

    public function isRepository(): bool
    {
        return $this->succeeds(['rev-parse', '--git-dir']);
    }

    public function hasCommits(): bool
    {
        return $this->succeeds(['rev-parse', '--verify', '--quiet', 'HEAD']);
    }

    public function hasRemote(): bool
    {
        return $this->output(['remote']) !== null;
    }

    public function show(string $sha, string $path): ?string
    {
        return $this->raw(['show', $sha.':'.$path]);
    }

    public function topLevel(): ?string
    {
        $output = $this->output(['rev-parse', '--show-toplevel']);

        if ($output === null) {
            return null;
        }

        $real = realpath($output);

        return $real === false ? $output : $real;
    }

    public function currentBranch(): ?string
    {
        $output = $this->output(['rev-parse', '--abbrev-ref', 'HEAD']);

        if ($output === null) {
            return null;
        }

        return $output === 'HEAD' ? null : $output;
    }

    public function currentSha(): ?string
    {
        return $this->output(['rev-parse', 'HEAD']);
    }

    public function defaultBranch(): ?string
    {
        $head = $this->output(['symbolic-ref', '--short', 'refs/remotes/origin/HEAD']);

        if ($head !== null) {
            $branch = preg_replace('#^origin/#', '', $head);

            if (is_string($branch) && $branch !== '') {
                return $branch;
            }
        }

        $configured = $this->output(['config', '--get', 'init.defaultBranch']);

        if ($configured !== null && $this->branchRefExists($configured)) {
            return $configured;
        }

        if ($this->branchRefExists('main')) {
            return 'main';
        }

        if ($this->branchRefExists('master')) {
            return 'master';
        }

        return null;
    }

    public function isAncestor(string $sha, string $head = 'HEAD'): bool
    {
        return $this->succeeds(['merge-base', '--is-ancestor', $sha, $head]);
    }

    /**
     * @return list<string>
     */
    public function branchNames(): array
    {
        $output = $this->raw(['for-each-ref', '--format=%(refname)', 'refs/heads', 'refs/remotes']);

        if ($output === null) {
            return [];
        }

        $names = [];

        foreach ($this->splitLines($output) as $ref) {
            if (str_starts_with($ref, 'refs/heads/')) {
                $names[substr($ref, strlen('refs/heads/'))] = true;

                continue;
            }

            if (! str_starts_with($ref, 'refs/remotes/')) {
                continue;
            }

            $tail = substr($ref, strlen('refs/remotes/'));
            $slash = strpos($tail, '/');

            if ($slash === false) {
                continue;
            }

            $branch = substr($tail, $slash + 1);

            if ($branch !== '' && $branch !== 'HEAD') {
                $names[$branch] = true;
            }
        }

        return array_keys($names);
    }

    private function hasRef(string $ref): bool
    {
        return $this->output(['rev-parse', '--verify', '--quiet', $ref]) !== null;
    }

    private function branchRefExists(string $branch): bool
    {
        return $this->hasRef('refs/heads/'.$branch) || $this->hasRef('refs/remotes/origin/'.$branch);
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $output): array
    {
        $lines = preg_split('/\R+/', trim($output), flags: PREG_SPLIT_NO_EMPTY);

        return $lines === false ? [] : $lines;
    }
}
