<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Change;

final readonly class LastRunTree
{
    private const FILE_NAME = 'last-run.json';

    /**
     * @param array<string, string> $tree
     */
    public function __construct(
        public string $branch,
        public ?string $sha,
        public array $tree,
        public int $finishedAt,
    ) {
    }

    public static function fromJson(string $json): ?self
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return null;
        }

        if (! array_key_exists('branch', $decoded) || ! is_string($decoded['branch'])) {
            return null;
        }

        if (! array_key_exists('sha', $decoded) || ! ($decoded['sha'] === null || is_string($decoded['sha']))) {
            return null;
        }

        if (! array_key_exists('tree', $decoded) || ! is_array($decoded['tree'])) {
            return null;
        }

        $tree = [];

        foreach ($decoded['tree'] as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                return null;
            }

            $tree[$key] = $value;
        }

        if (! array_key_exists('finishedAt', $decoded) || ! is_int($decoded['finishedAt'])) {
            return null;
        }

        return new self($decoded['branch'], $decoded['sha'], $tree, $decoded['finishedAt']);
    }

    public function toJson(): string
    {
        $json = json_encode([
            'branch' => $this->branch,
            'sha' => $this->sha,
            'tree' => (object) $this->tree,
            'finishedAt' => $this->finishedAt,
        ], JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }

    public static function load(string $stateDir): ?self
    {
        $content = @file_get_contents(self::path($stateDir));

        if ($content === false) {
            return null;
        }

        return self::fromJson($content);
    }

    public function save(string $stateDir): bool
    {
        if (! is_dir($stateDir) && ! @mkdir($stateDir, 0o775, true) && ! is_dir($stateDir)) {
            return false;
        }

        $path = self::path($stateDir);
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($tmp, $this->toJson()) === false) {
            return false;
        }

        if (@rename($tmp, $path) === false) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * @param list<string> $candidates
     * @return list<string>
     */
    public function filterUnchanged(array $candidates, ChangedFiles $changedFiles): array
    {
        if ($this->tree === []) {
            return $candidates;
        }

        $all = [];

        foreach ($candidates as $file) {
            $all[$file] = true;
        }

        foreach (array_keys($this->tree) as $file) {
            $all[$file] = true;
        }

        $remaining = [];

        foreach (array_keys($all) as $file) {
            $snapshot = $this->tree[$file] ?? null;
            $current = $changedFiles->currentHash($file);

            if ($snapshot === null || $current === null || $current !== $snapshot) {
                $remaining[] = $file;
            }
        }

        return $remaining;
    }

    private static function path(string $stateDir): string
    {
        return rtrim($stateDir, '/\\').'/'.self::FILE_NAME;
    }
}
