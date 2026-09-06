<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

use Manuglopez\Replay\Support\AtomicFile;

/**
 * Persists a Graph to "<stateDir>/graph.json", atomically. SPEC.md §4.1, §4.2.
 */
final class GraphStore
{
    public function __construct(
        private readonly string $stateDir,
        private readonly string $projectRoot,
    ) {
    }

    public function path(): string
    {
        return rtrim($this->stateDir, '/') . '/graph.json';
    }

    public function load(): ?Graph
    {
        $raw = $this->loadRaw();

        if ($raw === null) {
            return null;
        }

        return Graph::decode($raw, $this->projectRoot);
    }

    public function loadRaw(): ?string
    {
        return AtomicFile::read($this->path());
    }

    public function save(Graph $graph): bool
    {
        $json = $graph->encode();

        if ($json === null) {
            return false;
        }

        return AtomicFile::write($this->path(), $json);
    }

    public function delete(): bool
    {
        $path = $this->path();

        if (! file_exists($path)) {
            return true;
        }

        return @unlink($path);
    }
}
