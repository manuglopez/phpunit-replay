<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Analysis;

use FilesystemIterator;
use Iterator;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Support\Paths;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The static half of the `static_declaration_edges` feature: gives a declaration-only file
 * the dependency edges coverage can never produce for it.
 *
 * ## The rule
 *
 * A declaration-only file `D` becomes a dependency of test `T` when some file `F` that is
 * *already a behavioural dependency of `T`* refers to a name `D` declares. One hop, no
 * transitivity.
 *
 * `T`'s own file counts as one of those `F`s. That is not a widening of the rule but the
 * rule applied honestly — `T`'s method bodies are the very lines that ran — and it is the
 * only thing that reaches the hardest position: a test that reads a declaration-only file
 * *directly* and touches nothing else with a method body has no other hop source at all.
 * Stating it explicitly also makes the result independent of whether the coverage scope
 * happens to include the test directory.
 *
 * That is order-independent by construction: `F`'s body lines ran under `T` (that is what
 * made it a behavioural dependency — `Record\Recorder`), and the reference to `D` is written
 * in `F`'s source, so it holds for every test that reaches `F`, in every process, in every
 * worker distribution. Contrast the load-time coverage this replaces, which PHP credits to
 * whichever test in the process happened to `require` `D` first.
 *
 * ## Why only one hop
 *
 * A second hop would have to start from a declaration-only file, and a declaration-only file
 * is never a behavioural dependency of anything — so the only thing it could add is
 * `D2` referenced from `D1` referenced from `F`. Measured against the target project that is
 * two files out of 2,195 (named only from inside a declaration-only file), and missing it is
 * not a false green: a declaration-only file that receives no static edge at all is unknown
 * to the graph, and a change to it is therefore covered conservatively by the watch fallback
 * ({@see \Manuglopez\Replay\Select\ResiduePatterns}). The second hop would buy precision,
 * not safety, for 2 files out of 2,195.
 *
 * ## Cost
 *
 * {@see self::index()} parses every candidate source file once, then caches the result by
 * content hash ({@see FactsCache}), so only files that actually changed are re-parsed on
 * later passes. Measured cold on a 2,195-file Laravel project: 3.6s; warm: 0.5s, for an
 * 8.6 MB cache. Both the walk and the parsing stop at {@see self::MAX_FILES}; overrunning it
 * disables the index rather than the safety net, since an unindexed declaration-only file
 * simply stays unknown to the graph and falls through to the conservative watch fallback.
 */
final class StaticEdges
{
    /**
     * Ceiling on files scanned while building the index. A repository large enough to hit it
     * would spend minutes parsing on the first pass; giving up is safe (see the class
     * docblock) and loud (a warning), where silently grinding is neither.
     */
    private const MAX_FILES = 25_000;

    /**
     * Directory names never walked, whatever the scope says: none holds project PHP source,
     * and they are large enough to be worth not stat-ing.
     *
     * `vendor` is NOT on this list, and must not be: Laravel publishes package translations
     * into `lang/vendor/<package>/<locale>/*.php`, which are ordinary tracked project files
     * (27 of the 37 `lang/**.php` files in the project this was measured against). A blanket
     * segment match on "vendor" silently skipped every one of them. Composer's own
     * `vendor/` is instead recognised by what is inside it
     * ({@see self::isComposerVendorDir()}), and the root one is already outside the scope
     * (`Record\SourceScope::TOP_LEVEL_NOISE`, `Support\Paths::relative()`).
     *
     * @var list<string>
     */
    private const SKIP_DIRS = ['node_modules', '.git', '.svn', '.hg'];

    /** @var array<string, list<string>>|null fully-qualified name => declaration-only files (relative) */
    private ?array $index = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly SourceScope $scope,
        private readonly FactsCache $facts,
    ) {
    }

    /**
     * Adds one hop of static edges for each of `$testFilesRelative`, reading each test's
     * behavioural dependencies straight off `$graph` (so edges union'd in from earlier
     * passes count too — {@see Graph::unionEdges()}). Returns how many edges were added.
     *
     * @param list<string> $testFilesRelative
     */
    public function expand(Graph $graph, array $testFilesRelative): int
    {
        $index = $this->index();

        if ($index === []) {
            return 0;
        }

        $added = 0;

        foreach ($testFilesRelative as $testRelative) {
            $dependencies = $graph->dependenciesOf($testRelative);
            $known = array_fill_keys($dependencies, true);
            $targets = [];

            // The test file itself, then its behavioural dependencies. `$known` deliberately
            // does not contain the test file: a test whose own source names a declaration-only
            // file must end up with an edge to it.
            foreach ($this->hopSources($testRelative, $dependencies) as $dependency) {
                foreach ($this->facts->forRelative($dependency)->references as $name) {
                    foreach ($index[$name] ?? [] as $declarationFile) {
                        if (! isset($known[$declarationFile])) {
                            $targets[$declarationFile] = true;
                        }
                    }
                }
            }

            foreach (array_keys($targets) as $declarationFile) {
                $graph->link($testRelative, $declarationFile);
                $added++;
            }
        }

        return $added;
    }

    /**
     * The files whose references may be followed for `$testRelative`: the test's own file,
     * always, plus every dependency that is NOT itself declaration-only.
     *
     * Filtering the declaration-only ones out is what keeps "one hop" true *across runs*, and
     * it is not cosmetic. Edges accumulate ({@see Graph::unionEdges()}), so on the pass after
     * a declaration-only file `D1` was linked, `D1` would be sitting in the dependency list
     * and would be followed like any other dependency — quietly reaching `D2` on the second
     * pass and `D3` on the third. The graph would then depend on how many partial re-records
     * had happened rather than on the source tree, which is exactly the kind of
     * non-determinism that makes two machines compute different content keys for identical
     * code. The test file is exempt because it is the test: it must stay a hop source even
     * when it happens to be declaration-only itself (a stub test whose only method has an
     * empty body — a real shape, found in the project this was measured against).
     *
     * @param list<string> $dependencies
     * @return list<string>
     */
    private function hopSources(string $testRelative, array $dependencies): array
    {
        $sources = [$testRelative];

        foreach ($dependencies as $dependency) {
            if (! $this->facts->forRelative($dependency)->declarationOnly()) {
                $sources[] = $dependency;
            }
        }

        return $sources;
    }

    /**
     * The declaration-only files in scope, indexed by every fully-qualified class-like name
     * they declare. A `return [...]` config or language file declares no name at all, so it
     * never appears here — nothing can reference it lexically, and it is left to the
     * conservative watch fallback on purpose.
     *
     * @return array<string, list<string>>
     */
    public function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];
        $scanned = 0;

        foreach ($this->candidateFiles() as $relative) {
            if (++$scanned > self::MAX_FILES) {
                Warnings::warn(sprintf(
                    'static_declaration_edges: more than %d source files in scope; static edges disabled for this pass (changes to declaration-only files still force a conservative selection)',
                    self::MAX_FILES,
                ));

                return $this->index = [];
            }

            $facts = $this->facts->forRelative($relative);

            if (! $facts->declarationOnly()) {
                continue;
            }

            foreach ($facts->declares as $name) {
                $index[$name][] = $relative;
            }
        }

        foreach ($index as $name => $files) {
            $index[$name] = array_values(array_unique($files));
        }

        return $this->index = $index;
    }

    /**
     * Every `.php` file under the coverage scope's include directories, project-relative
     * and deduplicated (the scope's includes overlap: PHPUnit's `<source><include>` dirs
     * usually sit inside the top-level project dirs). Stops one past
     * {@see self::MAX_FILES} so neither the walk nor this array is unbounded.
     *
     * @return list<string>
     */
    private function candidateFiles(): array
    {
        $files = [];

        foreach ($this->scope->includes() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach ($this->walk($directory) as $absolute) {
                $relative = Paths::relative($this->projectRoot, $absolute);

                if ($relative === null || ! $this->scope->contains($absolute)) {
                    continue;
                }

                $files[$relative] = true;

                // One over the cap is enough for index() to notice the overrun and warn.
                // Collecting the rest would only grow this array for a result that is
                // already being thrown away.
                if (count($files) > self::MAX_FILES) {
                    return array_keys($files);
                }
            }
        }

        $out = array_keys($files);
        sort($out);

        return $out;
    }

    /**
     * Every `.php` file under `$directory`, absolute. `.blade.php` is skipped: php-parser
     * rejects Blade syntax, so it would only ever produce an unparseable entry, and Blade
     * already has its own reference walker (`Laravel\BladeReferences`).
     *
     * @return list<string>
     */
    private function walk(string $directory): array
    {
        $out = [];

        foreach ($this->leaves($directory) as $fileInfo) {
            if (! $fileInfo instanceof SplFileInfo || ! $fileInfo->isFile()) {
                continue;
            }

            $path = Paths::normalizeSeparators($fileInfo->getPathname());
            $lower = strtolower($path);

            if (! str_ends_with($lower, '.php') || str_ends_with($lower, '.blade.php')) {
                continue;
            }

            $out[] = $path;
        }

        return $out;
    }

    /**
     * Leaves under `$directory`, with unwanted directories pruned rather than filtered out
     * afterwards — a nested Composer `vendor/` is thousands of files this must not even
     * stat.
     *
     * @return Iterator<mixed, mixed>
     */
    private function leaves(string $directory): Iterator
    {
        $directories = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);

        $filtered = new RecursiveCallbackFilterIterator(
            $directories,
            static function (mixed $current): bool {
                if (! $current instanceof SplFileInfo) {
                    return false;
                }

                if (! $current->isDir()) {
                    return true;
                }

                if (in_array($current->getFilename(), self::SKIP_DIRS, true)) {
                    return false;
                }

                return ! self::isComposerVendorDir($current);
            },
        );

        return new RecursiveIteratorIterator(
            $filtered,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD,
        );
    }

    /** A `vendor/` Composer actually installed into, told apart from any other by its autoloader. */
    private static function isComposerVendorDir(SplFileInfo $directory): bool
    {
        return $directory->getFilename() === 'vendor'
            && is_file($directory->getPathname() . DIRECTORY_SEPARATOR . 'autoload.php');
    }
}
