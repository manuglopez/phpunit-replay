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
 * The static half of the `static_declaration_edges` feature: the edges a *name* justifies,
 * as opposed to the edges a *call* justifies.
 *
 * ## What partitions the two halves
 *
 * Not the shape of the file — the kind of signal. `Record\Recorder` keeps the edges coverage
 * can attribute order-independently: a test executed a line inside a function/method/closure
 * body, which only happens when something calls into it. This class adds the edges nothing
 * ever executes: a test names a symbol, and the name is written in source, so it reads the
 * same in every process and every worker distribution.
 *
 * An earlier version of this class split the work by file shape instead — the static index
 * held only files with no function body at all ({@see FileFacts::declarationOnly()}) — and
 * the two halves then failed to cover the middle. A file with even one method body whose
 * bodies a given test never entered got no behavioural edge (nothing called it) and no static
 * edge (the index declined it), while `Select\ResiduePatterns` also declined it because some
 * *other* test's coverage had given it a `fileId`. Adding one method to an enum was enough to
 * drop it out of the graph for every test that merely reads its cases. Indexing by
 * declaration removes the middle: every file that declares a name is reachable by name, and
 * every file whose body ran is reachable by coverage.
 *
 * ## The rule
 *
 * A file `D` that declares a name becomes a dependency of test `T` when `T`'s own source
 * names something `D` declares — or, when `D` has no function body at all, when any file that
 * is a *behavioural* dependency of `T` names it. One hop, no transitivity, and asymmetric on
 * purpose: {@see self::collect()} has the measurement that forced the asymmetry.
 *
 * `T`'s own file being a hop source is not a widening of the rule but the rule applied
 * honestly — `T`'s method bodies are the very lines that ran — and it is the only thing that
 * reaches the hardest position: a test that reads a declaration-only file *directly* and
 * touches nothing else with a method body has no other hop source at all. Stating it
 * explicitly also makes the result independent of whether the coverage scope happens to
 * include the test directory.
 *
 * ## Why only one hop, and why provenance decides it
 *
 * The hop sources are the dependencies *this run's coverage* reported, never the graph's
 * dependency list ({@see self::expand()}). That distinction is the whole determinism
 * guarantee. Edges accumulate across passes ({@see Graph::unionEdges()}), so a static edge
 * added on one pass sits in the graph on the next; following the graph would reach `D2` from
 * `D1` on pass two and `D3` on pass three, and the graph would then depend on how many
 * partial re-records had happened rather than on the source tree — exactly the kind of
 * non-determinism that makes two machines compute different content keys for identical code.
 * A behavioural edge, by contrast, is re-derived from coverage on every pass, so the hop
 * source set is a function of the source tree alone.
 *
 * Stopping at one hop costs precision, never safety: a file that receives no static edge at
 * all is unknown to the graph, and a change to it is therefore covered conservatively by the
 * watch fallback ({@see \Manuglopez\Replay\Select\ResiduePatterns}).
 *
 * ## Cost
 *
 * {@see self::index()} parses every candidate source file once, then caches the result by
 * content hash ({@see FactsCache}), so only files that actually changed are re-parsed on
 * later passes. Measured cold on a 2,195-file Laravel project: 3.6s; warm: 0.5s, for an
 * 8.6 MB cache. Both the walk and the parsing stop at {@see self::MAX_FILES}; overrunning it
 * disables the index rather than the safety net, since an unindexed file simply stays unknown
 * to the graph and falls through to the conservative watch fallback.
 *
 * Indexing by declaration rather than by absence of bodies widens the index from the
 * declaration-only files to every declaring file — on the same project, from 42 indexed names
 * over 147 files to 1,821 names over 1,926 files. What that costs in *edges* is governed by
 * the asymmetry in {@see self::collect()}, which is where the numbers live.
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

    /** @var array<string, list<string>>|null fully-qualified name => the files declaring it (relative) */
    private ?array $index = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly SourceScope $scope,
        private readonly FactsCache $facts,
    ) {
    }

    /**
     * Adds one hop of static edges for every test in `$behaviouralEdges`. Returns how many
     * edges were added.
     *
     * `$behaviouralEdges` is this run's coverage-derived edge map (`Record\RunPartial::$edges`
     * widened to carry an entry for every executed test), and it is both the list of tests to
     * expand and the hop sources to expand them from:
     *
     *  - **Every executed test needs an entry, including one with an empty list.** A test with
     *    no behavioural edge at all is the case this class exists for — the docblock calls it
     *    the hardest position — and it is reachable with no configuration at all: a
     *    `<source><exclude>` covering the test directory, or any `--coverage-*` report
     *    (`Record\PiggybackCoverageDriver` reads PHPUnit's own coverage, already narrowed to
     *    `<source><include>`, so no test file appears in its own coverage). Keying the loop on
     *    `RunPartial::$edges` alone silently skipped exactly those tests, because
     *    `Record\Recorder::endTest()` only creates `perTestFiles[$test]` inside its loop over
     *    the files coverage reported.
     *  - **The hop sources must come from here, not from `$graph`.** See the class docblock:
     *    reading the graph would follow static edges added by earlier passes and grow the
     *    graph pass after pass.
     *
     * `$graph` is still what answers "does this test already have this edge", so a second pass
     * over an unchanged tree adds nothing.
     *
     * @param array<string, list<string>> $behaviouralEdges test file (relative) => the source
     *        files (relative) whose bodies ran under it in this run
     */
    public function expand(Graph $graph, array $behaviouralEdges): int
    {
        $index = $this->index();

        if ($index === []) {
            return 0;
        }

        $added = 0;

        foreach ($behaviouralEdges as $testRelative => $behavioural) {
            $known = array_fill_keys($graph->dependenciesOf($testRelative), true);
            $targets = [];

            // The test's own source reaches anything it names. `$known` deliberately does not
            // contain the test file, so a test whose own source names a declaring file ends
            // up with an edge to it even with no behavioural dependency at all.
            $this->collect($index, $testRelative, $known, $targets, true);

            foreach ($behavioural as $hopSource) {
                $this->collect($index, $hopSource, $known, $targets, false);
            }

            foreach (array_keys($targets) as $declaringFile) {
                $graph->link($testRelative, $declaringFile);
                $added++;
            }
        }

        return $added;
    }

    /**
     * Adds every file `$hopSource` names to `$targets`, subject to the asymmetry that keeps
     * this affordable.
     *
     * `$anyShape` is true only for the test's own file. From there, naming a symbol is the
     * signal, whatever the shape of the file declaring it: the test's source is the test, and
     * a name it writes is a dependency it has.
     *
     * From a behavioural dependency, only a file with **no function body at all** may be
     * reached. That is not shyness, it is where the hop stops paying for itself:
     *
     *  - A file with no body can never be attributed by coverage at all, in any process, for
     *    any test. The transitive hop is the only mechanism it will ever have, and its reach
     *    is small — measured on a 2,195-file Laravel project of 726 tests and 62,743 recorded
     *    edges, 730 edges (median 1 per test).
     *  - A file *with* bodies already gets a behavioural edge from every test that calls into
     *    it. The only edge it can be missing is the one from a test that names it without
     *    calling it, and for that the test's own source is the whole signal. Letting a
     *    behavioural dependency reach it as well adds 66,219 edges on that same project —
     *    +105.5%, median dependencies per test 82 → 173 — and 60% of them come from five
     *    files that name classes they merely *register*: `routes/web.php`, `routes/api.php`,
     *    `routes/breadcrumbs.php`, `routes/console.php` and one kitchen-sink model, each a
     *    behavioural dependency of 700 of the 726 tests and each naming dozens of unrelated
     *    controllers. Every test that hit any route would inherit an edge to every controller
     *    in the application. That is over-attribution with no safety to show for it: those
     *    controllers are reachable by coverage from the tests that exercise them, and by name
     *    from the tests that mention them.
     *
     * With the asymmetry the same project gains 1,781 edges instead of 66,219 — +2.84%,
     * median dependencies per test 82 → 84 — of which 730 are the declaration-only reach
     * above and 1,051 are the new "the test names it" edges. The five registration files
     * contribute exactly zero. The widest single gain from the new half is 190 tests, for a
     * backed enum with one method that 190 test files name directly, which is the earned case.
     *
     * @param array<string, list<string>> $index
     * @param array<string, true> $known
     * @param array<string, true> $targets
     */
    private function collect(array $index, string $hopSource, array $known, array &$targets, bool $anyShape): void
    {
        foreach ($this->facts->forRelative($hopSource)->references as $name) {
            foreach ($index[$name] ?? [] as $declaringFile) {
                if (isset($known[$declaringFile]) || isset($targets[$declaringFile])) {
                    continue;
                }

                if ($anyShape || $this->facts->forRelative($declaringFile)->declarationOnly()) {
                    $targets[$declaringFile] = true;
                }
            }
        }
    }

    /**
     * Every file in scope that declares a class-like name, indexed by every fully-qualified
     * name it declares — whether or not it also has method bodies. A file with bodies is
     * already reachable by coverage *when something calls into it*; being in this index is
     * what makes it reachable by the tests that only ever name it.
     *
     * A `return [...]` config or language file declares no name at all, so it never appears
     * here — nothing can reference it lexically, and it is left to the conservative watch
     * fallback on purpose ({@see \Manuglopez\Replay\Select\ResiduePatterns}). An unparseable
     * file is absent for the same reason: {@see FileFacts::unparseable()} declares nothing.
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
                    'static_declaration_edges: more than %d source files in scope; static edges disabled for this pass (changes to files the graph has no edge for still force a conservative selection)',
                    self::MAX_FILES,
                ));

                return $this->index = [];
            }

            $facts = $this->facts->forRelative($relative);

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
