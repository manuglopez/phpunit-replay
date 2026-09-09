<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Change;

use Manuglopez\Replay\Cache\ContentHash;

/**
 * Derived from Pest (© Nuno Maduro, MIT). @see https://github.com/pestphp/pest/blob/17d709e/src/Plugins/Tia/ChangedFiles.php
 */
final readonly class ChangedFiles
{
    private const SCAN_TIMEOUT = 60.0;

    private Git $git;

    public function __construct(
        private string $projectRoot,
        ?Git $git = null,
    ) {
        $this->git = $git ?? new Git($this->projectRoot);
    }

    /**
     * @return list<string>|null null when the sha is unreachable (baseline unusable) or git failed
     */
    public function since(?string $sha): ?array
    {
        if ($sha !== null && $sha !== '' && ! $this->git->isAncestor($sha)) {
            return null;
        }

        $files = [];

        if ($sha !== null && $sha !== '') {
            $diff = $this->diffSinceSha($sha);

            if ($diff === null) {
                return null;
            }

            array_push($files, ...$diff);
        }

        $working = $this->workingTreeChanges();

        if ($working === null) {
            return null;
        }

        array_push($files, ...$working);

        $unique = [];

        foreach ($files as $file) {
            if ($file !== '') {
                $unique[$file] = true;
            }
        }

        $filtered = $this->filterIgnored($unique);

        if ($filtered === null) {
            return null;
        }

        $candidates = array_keys($filtered);

        if ($sha !== null && $sha !== '') {
            $candidates = $this->filterContentUnchanged($candidates, $sha);
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates);

        return $candidates;
    }

    /**
     * @param list<string> $files
     * @return array<string, string>
     */
    public function snapshotTree(array $files): array
    {
        $out = [];

        foreach ($files as $file) {
            $absolute = $this->absolute($file);

            if (! is_file($absolute)) {
                $out[$file] = '';

                continue;
            }

            $out[$file] = ContentHash::of($absolute) ?? '';
        }

        return $out;
    }

    public function currentHash(string $relativePath): ?string
    {
        $absolute = $this->absolute($relativePath);

        if (! is_file($absolute)) {
            return null;
        }

        return ContentHash::of($absolute);
    }

    /**
     * @return list<string>|null
     */
    private function diffSinceSha(string $sha): ?array
    {
        $output = $this->git->withTimeout(self::SCAN_TIMEOUT)->raw(['diff', '--name-only', '--no-renames', $sha.'..HEAD']);

        if ($output === null) {
            return null;
        }

        return $this->splitLines($output);
    }

    /**
     * @return list<string>|null
     */
    private function workingTreeChanges(): ?array
    {
        $output = $this->git->withTimeout(self::SCAN_TIMEOUT)->raw(['status', '--porcelain=v1', '-z', '--untracked-files=all']);

        if ($output === null) {
            return null;
        }

        if ($output === '') {
            return [];
        }

        $records = explode("\x00", rtrim($output, "\x00"));
        $files = [];
        $count = count($records);

        for ($i = 0; $i < $count; $i++) {
            $record = $records[$i];

            if (strlen($record) < 4) {
                continue;
            }

            $status = substr($record, 0, 2);
            $path = substr($record, 3);

            if ($status[0] === 'R' || $status[0] === 'C') {
                $files[] = $path;

                if (isset($records[$i + 1]) && $records[$i + 1] !== '') {
                    $files[] = $records[$i + 1];
                    $i++;
                }

                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * @param array<string, true> $candidates
     * @return array<string, true>|null
     */
    private function filterIgnored(array $candidates): ?array
    {
        if ($candidates === []) {
            return $candidates;
        }

        $ignored = $this->git->ignored(array_keys($candidates));

        if ($ignored === null) {
            return null;
        }

        foreach (array_keys($ignored) as $path) {
            unset($candidates[$path]);
        }

        return $candidates;
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function filterContentUnchanged(array $files, string $sha): array
    {
        $remaining = [];

        foreach ($files as $file) {
            $current = $this->currentHash($file);

            if ($current === null) {
                // deleted or unreadable on disk -> stays
                $remaining[] = $file;

                continue;
            }

            $baselineContent = $this->git->show($sha, $file);

            if ($baselineContent === null) {
                // did not exist at the baseline sha -> stays
                $remaining[] = $file;

                continue;
            }

            if ($current !== ContentHash::ofContent($baselineContent, $file)) {
                $remaining[] = $file;
            }
        }

        return $remaining;
    }

    private function absolute(string $relative): string
    {
        return rtrim($this->projectRoot, '/\\').'/'.ltrim($relative, '/');
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
