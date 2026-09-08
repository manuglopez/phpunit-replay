<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Analysis;

use EmptyIterator;
use FilesystemIterator;
use Generator;
use Iterator;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Console\Runner\Warnings;
use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Support\Paths;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

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
 * Names are matched case-insensitively over ASCII, because that is how PHP resolves a class
 * name — see {@see self::fold()}.
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
 * 8.6 MB cache. {@see self::MAX_FILES} is a warning threshold, not a ceiling — see the
 * constant.
 *
 * Indexing by declaration rather than by absence of bodies widens the index from the
 * declaration-only files to every declaring file — on the same project, from 42 indexed names
 * over 147 files to 1,821 names over 1,926 files. What that costs in *edges* is governed by
 * the asymmetry in {@see self::collect()}, which is where the numbers live.
 */
final class StaticEdges
{
    /**
     * How many candidate files it takes before the first pass is worth warning about.
     *
     * This used to disable the index: over the cap, `index()` warned and returned `[]`. That
     * was not a safe default, it was a one-sided one. The behavioural half of the feature
     * lives in the PHPUnit child (`Record\Recorder`), which has already dropped every
     * load-time-only edge by the time this class runs in the parent — so giving up here
     * turned the flag into a pure edge *remover* with nothing added back. Worse, it did not
     * even degrade cleanly: a file that received static edges on an earlier pass still holds
     * a `fileId`, so `Select\ResiduePatterns` declines to select conservatively for it, and
     * the missing edges become a silent under-selection rather than a loud one.
     *
     * So the index is always built, and crossing this many files only says so out loud. The
     * cost is bounded and one-time (the analysis cache absorbs every later pass), the flag is
     * opt-in, and a project big enough to notice gets told before it waits.
     *
     * The constructor takes it as a parameter so the over-threshold path has a test that does
     * not involve creating 25,000 files.
     */
    public const MAX_FILES = 25_000;

    /**
     * Directory names never walked, whatever the scope says: none holds project PHP source,
     * and they are large enough to be worth not stat-ing.
     *
     * This is a pruning optimisation, never a filter: `Record\SourceScope::contains()` still
     * decides whether any file that survives the walk is in scope
     * ({@see self::candidateFiles()}), and it excludes strictly more than this list does. The
     * only thing that would make the two disagree is a `<source><include>` pointing *inside*
     * one of these, which is not a configuration this feature is willing to walk.
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

    /** @var array<string, list<string>>|null folded declared name => the files declaring it (relative) */
    private ?array $index = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly SourceScope $scope,
        private readonly FactsCache $facts,
        private readonly int $maxFiles = self::MAX_FILES,
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
            foreach ($index[self::fold($name)] ?? [] as $declaringFile) {
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
     * Every file in scope that declares a class-like name, indexed by {@see self::fold()} of
     * every fully-qualified name it declares — whether or not it also has method bodies. A
     * file with bodies is already reachable by coverage *when something calls into it*; being
     * in this index is what makes it reachable by the tests that only ever name it.
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

        $candidates = $this->candidateFiles();

        // Before the parsing, not during it: the old check fired on candidate 25,001, by
        // which point 25,000 `analysis/` entries had already been written for a result that
        // was about to be discarded.
        if (count($candidates) > $this->maxFiles) {
            Warnings::warn(sprintf(
                'static_declaration_edges: %d source files in scope; the first pass will parse all of them (later passes read <stateDir>/analysis/ instead)',
                count($candidates),
            ));
        }

        $index = [];

        foreach ($candidates as $relative) {
            foreach ($this->facts->forRelative($relative)->declares as $name) {
                $index[self::fold($name)][] = $relative;
            }
        }

        foreach ($index as $name => $files) {
            $index[$name] = array_values(array_unique($files));
        }

        return $this->index = $index;
    }

    /**
     * A name as PHP compares it: class, interface, trait and enum names are case-insensitive
     * over ASCII (and only over ASCII — bytes >= 0x80 in an identifier are compared exactly,
     * which is also what `strtolower()` has done since PHP 8.2).
     *
     * Without this the index was byte-exact and PHP was not. `use App\Models\user;` followed
     * by `user::find(1)` resolves to the reference `App\Models\user` — `NameContext` returns
     * the fully-qualified name as written — while the declaration indexes as
     * `App\Models\User`: no match, no edge, and no diagnostic to say so. The same held for a
     * differently-cased namespace segment, which PHP also accepts, and for the string-literal
     * path (`class_exists('app\Models\User')`).
     */
    private static function fold(string $name): string
    {
        return strtolower($name);
    }

    /**
     * Every `.php` file under the coverage scope's include directories, project-relative,
     * deduplicated (the scope's includes overlap: PHPUnit's `<source><include>` dirs
     * usually sit inside the top-level project dirs) and sorted, so which files get parsed
     * never depends on the order the filesystem hands them over.
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
            }
        }

        $out = array_keys($files);
        sort($out);

        return $out;
    }

    /**
     * Every `.php` file under `$directory`, absolute, yielded as the walk finds them so one
     * enormous include directory is never materialised whole. `.blade.php` is skipped:
     * php-parser rejects Blade syntax, so it would only ever produce an unparseable entry,
     * and Blade already has its own reference walker (`Laravel\BladeReferences`).
     *
     * Never throws, the same guarantee {@see DeclarationScanner} gives. A directory that
     * `is_dir()` accepts but the process cannot open — one root-owned directory in a CI
     * image is enough — makes `RecursiveDirectoryIterator` throw `UnexpectedValueException`
     * from its *constructor* and `RecursiveIteratorIterator` throw it from `rewind()`;
     * `CATCH_GET_CHILD` covers neither, only `getChildren()`. Nothing between here and
     * `Cache\GraphUpdater::apply()` catches it, so the whole pass used to abort.
     *
     * @return Generator<int, string>
     */
    private function walk(string $directory): Generator
    {
        $leaves = $this->leaves($directory);

        try {
            $leaves->rewind();
        } catch (Throwable) {
            return;
        }

        while (true) {
            try {
                if (! $leaves->valid()) {
                    return;
                }

                $current = $leaves->current();
            } catch (Throwable) {
                return;
            }

            $path = self::sourcePath($current);

            if ($path !== null) {
                yield $path;
            }

            try {
                $leaves->next();
            } catch (Throwable) {
                return;
            }
        }
    }

    /** `$leaf`'s normalised path when it is a `.php` file this classifier will read, else null. */
    private static function sourcePath(mixed $leaf): ?string
    {
        if (! $leaf instanceof SplFileInfo || ! $leaf->isFile()) {
            return null;
        }

        $path = Paths::normalizeSeparators($leaf->getPathname());
        $lower = strtolower($path);

        if (! str_ends_with($lower, '.php') || str_ends_with($lower, '.blade.php')) {
            return null;
        }

        return $path;
    }

    /**
     * Leaves under `$directory`, with unwanted directories pruned rather than filtered out
     * afterwards — a nested Composer `vendor/` is thousands of files this must not even
     * stat. An `EmptyIterator` when the directory cannot be opened at all; {@see self::walk()}
     * explains why that is not an exception.
     *
     * @return Iterator<mixed, mixed>
     */
    private function leaves(string $directory): Iterator
    {
        try {
            $directories = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (Throwable) {
            return new EmptyIterator();
        }

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
