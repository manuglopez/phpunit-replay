<?php

declare(strict_types=1);

namespace Orlegitech\Replay\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A throw-away git repository in a temp directory, for tests that need real git behaviour.
 */
final class GitRepo
{
    private function __construct(public readonly string $root)
    {
    }

    /** Creates a repository in a fresh temp dir on branch "main", with identity configured. */
    public static function init(?string $root = null, string $branch = 'main'): self
    {
        $root ??= TempDir::make('git');
        $repo = new self($root);

        $repo->git('init', '-q', '-b', $branch);
        $repo->git('config', 'user.name', 'Replay Tests');
        $repo->git('config', 'user.email', 'tests@example.invalid');
        $repo->git('config', 'commit.gpgsign', 'false');
        $repo->git('config', 'core.autocrlf', 'false');

        return $repo;
    }

    /** Copies a directory tree into a fresh repository and commits it. */
    public static function fromTree(string $sourceDir, string $message = 'initial'): self
    {
        $repo = self::init();
        TempDir::copyTree($sourceDir, $repo->root);
        $repo->commitAll($message);

        return $repo;
    }

    public function write(string $relative, string $content): void
    {
        TempDir::write($this->path($relative), $content);
    }

    public function read(string $relative): string
    {
        $content = file_get_contents($this->path($relative));

        if ($content === false) {
            throw new RuntimeException('Cannot read ' . $relative);
        }

        return $content;
    }

    public function delete(string $relative): void
    {
        @unlink($this->path($relative));
    }

    public function rename(string $from, string $to): void
    {
        $target = $this->path($to);
        $parent = dirname($target);

        if (! is_dir($parent) && ! @mkdir($parent, 0o775, true) && ! is_dir($parent)) {
            throw new RuntimeException('Cannot create ' . $parent);
        }

        if (! @rename($this->path($from), $target)) {
            throw new RuntimeException('Cannot rename ' . $from);
        }
    }

    public function commitAll(string $message = 'update'): string
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', $message);

        return $this->sha();
    }

    public function sha(string $ref = 'HEAD'): string
    {
        return trim($this->git('rev-parse', $ref));
    }

    public function checkout(string $branch, bool $create = false): void
    {
        $create ? $this->git('checkout', '-q', '-b', $branch) : $this->git('checkout', '-q', $branch);
    }

    public function branch(): string
    {
        return trim($this->git('rev-parse', '--abbrev-ref', 'HEAD'));
    }

    public function path(string $relative): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** Runs git in the repository and returns stdout; throws on non-zero exit. */
    public function git(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->root);
        $process->setTimeout(30.0);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "git %s failed (%d): %s",
                implode(' ', $arguments),
                $process->getExitCode() ?? -1,
                $process->getErrorOutput(),
            ));
        }

        return $process->getOutput();
    }

    public function destroy(): void
    {
        TempDir::remove($this->root);
    }
}
