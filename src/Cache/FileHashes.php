<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Cache;

/**
 * {@see ContentHash::of()} of project-relative paths, read once per pass.
 *
 * A pass needs the same hashes several times: the content key of every test file it might
 * serve (`Select\StampAudit`), the non-edge input digest of the same files
 * (`Select\NonEdgeInputs`), and both again for what it executed (`GraphUpdater`) and what it
 * publishes. On a real project the dependency files alone take 0.27–0.57 s to hash, so each
 * file is hashed at most once per pass and every consumer reads the same value.
 *
 * One instance per pass, never persisted. Reading a file once also means every stamp a pass
 * writes describes one moment of the tree (the first time the pass looked at that file),
 * rather than whatever the suite left behind in it.
 */
final class FileHashes
{
    /** @var array<string, ?string> */
    private array $hashes = [];

    public function __construct(private readonly string $projectRoot)
    {
    }

    /** Null when the file does not exist or cannot be read. */
    public function of(string $relative): ?string
    {
        if (array_key_exists($relative, $this->hashes)) {
            return $this->hashes[$relative];
        }

        return $this->hashes[$relative] = ContentHash::of(rtrim($this->projectRoot, '/') . '/' . $relative);
    }
}
