<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Analysis;

use Manuglopez\Replay\Cache\ContentHash;
use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;
use Manuglopez\Replay\Support\Paths;

/**
 * Content-addressed cache for {@see FileFacts}, in the project's own state directory
 * (`<stateDir>/analysis/v<rules>/<xx>/<hash>.json`, sharded by the first two hex
 * characters of the hash, under {@see DeclarationScanner::RULES_VERSION}).
 *
 * The key is `Cache\ContentHash::of()` — the same normalised hash the content key is built
 * from, so a comment-only edit does not invalidate a file's facts, and two branches with
 * the same file share one entry. Entries are therefore immutable: each is written exactly
 * once and never updated, which is what makes this safe for Paratest workers scanning the
 * same project concurrently (no read-modify-write, and {@see AtomicFile} rules out a torn
 * file). A lost write costs one re-parse, never a wrong answer.
 *
 * The `v<rules>` path segment is not decoration: the content hash answers "has this file
 * changed", never "have we changed our mind about what this file means". Without it, a
 * change to the classification rules would keep serving the old verdict for every unchanged
 * file on every machine — see {@see DeclarationScanner::RULES_VERSION}.
 *
 * `$byPath` memoises within the process, keyed by absolute path: `Record\Recorder` asks
 * about the same few hundred files once per test, and re-hashing them every time would
 * cost more than the parse it saves.
 */
final class FactsCache
{
    /** @var array<string, FileFacts> absolute path => facts */
    private array $byPath = [];

    private int $hits = 0;

    private int $misses = 0;

    public function __construct(
        private readonly string $stateDir,
        private readonly string $projectRoot,
        private readonly DeclarationScanner $scanner = new DeclarationScanner(),
    ) {
    }

    public function forRelative(string $relativeFile): FileFacts
    {
        return $this->for(Paths::join($this->projectRoot, $relativeFile));
    }

    /**
     * The facts for one file, or {@see FileFacts::unparseable()} when it is not this
     * classifier's business.
     *
     * Blade templates are refused by extension rather than by parse failure: php-parser
     * reads one as a single inline-HTML statement and reports no function bodies, which
     * would classify it as declaration-only and let `Record\Recorder` drop the edge
     * `Laravel\BladeTracker` linked by hand (`Recorder::linkSource()`). Blade has its own
     * reference walker (`Laravel\BladeReferences`) and its own selection rule
     * (`Select\Rules\BladeRule`); neither wants an opinion from here.
     */
    public function for(string $absoluteFile): FileFacts
    {
        if (isset($this->byPath[$absoluteFile])) {
            return $this->byPath[$absoluteFile];
        }

        if (str_ends_with(strtolower($absoluteFile), '.blade.php')) {
            return $this->byPath[$absoluteFile] = FileFacts::unparseable();
        }

        $hash = ContentHash::of($absoluteFile);

        if ($hash === null) {
            return $this->byPath[$absoluteFile] = FileFacts::unparseable();
        }

        $stored = $this->read($hash);

        if ($stored !== null) {
            $this->hits++;

            return $this->byPath[$absoluteFile] = $stored;
        }

        $this->misses++;
        $facts = $this->scanner->scan($absoluteFile);
        $this->write($hash, $facts);

        return $this->byPath[$absoluteFile] = $facts;
    }

    /** @return array{hits: int, misses: int} for the debug line, not for control flow */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }

    private function read(string $hash): ?FileFacts
    {
        $json = AtomicFile::read($this->pathFor($hash));

        if ($json === null) {
            return null;
        }

        return FileFacts::fromArray(Json::decodeArray($json));
    }

    private function write(string $hash, FileFacts $facts): void
    {
        $json = Json::encode($facts->toArray());

        if ($json !== null) {
            AtomicFile::write($this->pathFor($hash), $json);
        }
    }

    private function pathFor(string $hash): string
    {
        return rtrim($this->stateDir, '/')
            . '/analysis/v' . DeclarationScanner::RULES_VERSION
            . '/' . substr($hash, 0, 2)
            . '/' . $hash . '.json';
    }
}
