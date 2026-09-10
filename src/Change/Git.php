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

    /**
     * Batches `git check-ignore --no-index -z --stdin` over `$paths` in one process (stdin
     * avoids ARG_MAX; callers must never invoke this once per path) — shared by
     * `Change\ChangedFiles::filterIgnored()` and `Cache\GraphUpdater`'s edge filter, the two
     * places that need "which of these does git ignore" (docs/SPEC.md §7.3/§7.1). `--no-index`
     * means the check runs off `.gitignore` patterns alone, regardless of whether a path is
     * already tracked in the index.
     *
     * Returns the ignored subset of `$paths` (relative or absolute; relative paths resolve
     * against `$this->directory`, like every other command here), or null when git itself
     * failed — no git binary, not a repository, a subprocess error, or a timeout (exit code
     * neither 0 nor 1). Callers decide what "unknown" means for them; every caller today
     * fails open (treats null as "nothing is ignored").
     *
     * @param list<string> $paths
     * @return array<string, true>|null
     */
    public function ignored(array $paths): ?array
    {
        if ($paths === []) {
            return [];
        }

        $result = $this->result(['check-ignore', '--no-index', '-z', '--stdin'], implode("\x00", $paths));

        if ($result['exitCode'] !== 0 && $result['exitCode'] !== 1) {
            return null;
        }

        $output = $result['output'];

        if ($output === '') {
            return [];
        }

        $ignored = [];

        foreach (explode("\x00", rtrim($output, "\x00")) as $path) {
            if ($path !== '') {
                $ignored[$path] = true;
            }
        }

        return $ignored;
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

    /**
     * The URL configured for the `origin` remote, or null when there is no `origin` (no remote
     * at all, or one under a different name).
     *
     * Deliberately `config --get` rather than `remote get-url`: the latter expands
     * `url.<base>.insteadOf` rewrites, and what
     * {@see \Manuglopez\Replay\Cache\ProjectKey::originIdentity()} hashes into the shared cache's
     * project key is the literal config value — so `remote:init`, which prints that identity and
     * proposes a sibling URL from the same string, has to read the same one.
     */
    public function originUrl(): ?string
    {
        return $this->output(['config', '--get', 'remote.origin.url']);
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
