<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Analysis;

use Manuglopez\Replay\Support\AtomicFile;
use Manuglopez\Replay\Support\Json;
use Manuglopez\Replay\Support\Paths;

/**
 * Content-addressed cache for {@see FileFacts}, in the project's own state directory
 * (`<stateDir>/analysis/v<rules>/<xx>/<hash>.json`, sharded by the first two hex
 * characters of the hash, under {@see DeclarationScanner::RULES_VERSION}).
 *
 * ## The key is the exact bytes, deliberately unlike `Cache\ContentHash`
 *
 * `Cache\ContentHash::of()` — the hash the *content key* is built from — discards
 * `T_WHITESPACE`, `T_COMMENT` and `T_DOC_COMMENT`, which is right for "did this file change
 * in a way that can change behaviour" and wrong here, because {@see FileFacts::$bodies} is a
 * list of **line ranges**. Keyed by the normalised hash, adding a four-line docblock above a
 * method produced a cache *hit* and served `[[7, 7]]` for a file whose body had moved to line
 * 11: `Record\Recorder::filesWithExecutedLines()` then asked `coversAnyBodyLine([11])`, got
 * false, and the file became a dependency of no test at all. Deleting comment lines is the
 * same bug with the opposite sign — a load-time line slides *into* a stale range and the file
 * is credited as behavioural to whichever test loaded it first, the very non-determinism this
 * feature exists to remove. Entries are content-addressed and immutable, so a wrong answer
 * would have been permanent for that content, and the `v<rules>` segment could not help: the
 * content changed and the rules did not.
 *
 * So the key is `xxh128` of the raw bytes. A comment-only edit now costs one re-parse, which
 * is the whole price of describing the payload honestly.
 *
 * ## One read, and nothing stored that a second run could answer differently
 *
 * The bytes are read exactly once, and both the key and the facts come from that one string
 * ({@see DeclarationScanner::scanSource()}). Hashing the file and then letting the scanner
 * open it again left a window — codegen, an `artisan` publish, an editor save, a
 * `git checkout` while Paratest workers scan — in which version B's facts were stored under
 * version A's hash, permanently, for every machine sharing the state directory.
 *
 * A failed scan is never stored. {@see FileFacts::unparseable()} covers a genuine syntax
 * error *and* any swallowed `Throwable`: EMFILE under N Paratest workers, memory pressure,
 * causes with nothing to do with the source. Persisting that made `declarationOnly()` false
 * forever for that content, so a genuinely declaration-only enum could never enter the index
 * or receive a static edge again short of `prune --all`. Re-parsing a file that cannot be
 * parsed costs one failed parse per process — `$byPath` absorbs the rest — and buys back the
 * property that nothing in here is a guess.
 *
 * Entries are written exactly once and never updated, which is what makes this safe for
 * Paratest workers scanning the same project concurrently (no read-modify-write, and
 * {@see AtomicFile} rules out a torn file). A lost write costs one re-parse, never a wrong
 * answer — which is also why `write()` ignores {@see AtomicFile::write()}'s return value,
 * and why a file whose identifiers are not valid UTF-8 (a PHP identifier may hold bytes
 * >= 0x80, so a Latin-1 `enum Café` is legal) is simply never stored: `Json::encode()`
 * refuses it, and re-parsing it every process is the cheap, correct outcome. Encoding
 * around it would mean either a lossy name or a second payload shape, and the names in
 * here are matched against each other.
 *
 * The `v<rules>` path segment is not decoration: the content hash answers "has this file
 * changed", never "have we changed our mind about what this file means". Without it, a
 * change to the classification rules would keep serving the old verdict for every unchanged
 * file on every machine — see {@see DeclarationScanner::RULES_VERSION}.
 *
 * `$byPath` memoises within the process, keyed by absolute path: `Record\Recorder` asks
 * about the same few hundred files once per test, and re-hashing them every time would
 * cost more than the parse it saves. It is never invalidated, and does not need to be — no
 * process holding one of these rewrites the project's own source while it runs.
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
     * (`Laravel\Rules\BladeRule`); neither wants an opinion from here.
     */
    public function for(string $absoluteFile): FileFacts
    {
        if (isset($this->byPath[$absoluteFile])) {
            return $this->byPath[$absoluteFile];
        }

        if (str_ends_with(strtolower($absoluteFile), '.blade.php')) {
            return $this->byPath[$absoluteFile] = FileFacts::unparseable();
        }

        $source = @file_get_contents($absoluteFile);

        if ($source === false) {
            return $this->byPath[$absoluteFile] = FileFacts::unparseable();
        }

        $hash = self::keyFor($source);
        $stored = $this->read($hash);

        if ($stored !== null) {
            $this->hits++;

            return $this->byPath[$absoluteFile] = $stored;
        }

        $this->misses++;
        $facts = $this->scanner->scanSource($source);

        if ($facts->parsed) {
            $this->write($hash, $facts);
        }

        return $this->byPath[$absoluteFile] = $facts;
    }

    /** @return array{hits: int, misses: int} for the debug line, not for control flow */
    public function stats(): array
    {
        return ['hits' => $this->hits, 'misses' => $this->misses];
    }

    /** The exact bytes the payload's line ranges describe — see the class docblock. */
    private static function keyFor(string $source): string
    {
        return hash('xxh128', $source);
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
