<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use PHPUnit\TextUI\CliArguments\Builder as CliArgumentsBuilder;
use PHPUnit\TextUI\CliArguments\Configuration as CliConfiguration;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Merger;
use PHPUnit\TextUI\Configuration\SourceMapper;
use PHPUnit\TextUI\XmlConfiguration\Loader as XmlConfigurationLoader;
use SebastianBergmann\CodeCoverage\Filter;
use Throwable;

/**
 * Thin, version-tolerant reads of a PHPUnit `Configuration` object: everything that
 * differs between PHPUnit 11.5, 12 and 13 (e.g. `--repeat`/`--retry`, absent before 13.3)
 * is abstracted here behind `method_exists` checks, per docs/INTERNALS.md.
 */
final readonly class ConfigurationReader
{
    /**
     * `$cliConfiguration` is the CLI-only half `Configuration\Merger` merges with the XML
     * half into `$configuration` — kept separately so {@see self::hasPartialSelection()}
     * can answer "did the command line ask for a subset" without the merged object's own
     * contamination (see that method's docblock). Null on the one path that has no CLI
     * half to hand it (an in-process boot that could not reliably reconstruct one from
     * `$_SERVER['argv']` — {@see self::cliConfigurationFromArgv()}); every OTHER read here
     * (`shouldRerun()`, `sourceIncludeDirectories()`, `repeatOrRetryRequested()`, ...) is
     * unaffected either way, since none of them consult it.
     */
    public function __construct(
        private Configuration $configuration,
        private ?CliConfiguration $cliConfiguration = null,
    ) {
    }

    /**
     * Builds a `Configuration` from an XML file plus CLI-style arguments, without ever
     * touching `PHPUnit\TextUI\Configuration\Registry` (this may run inside a process
     * that is not the one PHPUnit's own Registry belongs to).
     *
     * A dummy `--configuration <xmlFile>` pair is always prepended to the CLI parameters:
     * `SebastianBergmann\CliParser\Parser::parse()` unconditionally treats element 0 of the
     * given array as a program name and discards it (mirroring `$argv[0]`), so a bare
     * positional argument (e.g. an explicit test file path) passed as the *only* element
     * would otherwise silently vanish. Prefixing with an option side-steps that.
     *
     * @param list<string> $cliArguments
     */
    public static function fromXmlFile(string $xmlFile, array $cliArguments = []): self
    {
        $xml = (new XmlConfigurationLoader())->load($xmlFile);

        $cli = (new CliArgumentsBuilder())->fromParameters([
            '--configuration',
            $xmlFile,
            ...$cliArguments,
        ]);

        $configuration = (new Merger())->merge($cli, $xml);

        return new self($configuration, $cli);
    }

    /**
     * The CLI half alone, reconstructed from the REAL invocation (`$_SERVER['argv']`) with
     * the same `CliArguments\Builder` {@see self::fromXmlFile()} uses — for the one caller
     * that has no wrapper in front of it and therefore no already-known argument list to
     * build from: `PHPUnit\ReplayState::bootInProcess()`, which is handed PHPUnit's own
     * already-MERGED `Configuration` and cannot recover which half a group/testsuite came
     * from out of it (`Configuration\Merger` does not retain that provenance).
     *
     * Null on argv being absent/empty or on any parse failure — both left for the caller to
     * treat as "cannot determine the CLI half", which {@see self::hasPartialSelection()}
     * then answers the only safe way: as if a selection WERE present. Never silently
     * degrades to "no selection", which would let e.g. a Paratest worker whose own
     * argv does not resemble the user's invocation replay (or record into) the cache for a
     * run the user asked to narrow.
     */
    public static function cliConfigurationFromArgv(): ?CliConfiguration
    {
        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv) || $argv === []) {
            return null;
        }

        /** @var list<string> $arguments */
        $arguments = array_values(array_filter($argv, 'is_string'));

        if ($arguments === []) {
            return null;
        }

        try {
            return (new CliArgumentsBuilder())->fromParameters($arguments);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * True when the COMMAND LINE alone asked PHPUnit to run something narrower than the
     * full suite (`--filter`, `--exclude-filter`, `--group`, `--exclude-group`,
     * `--testsuite`, `--exclude-testsuite`, or an explicit path/file argument). SPEC.md
     * §3.1. Gates recording (`Console\Runner\RunPipeline`, `PHPUnit\ReplayState`): a CLI
     * selection means "run exactly this", so nothing observed under it may enter the cache.
     *
     * Bug fix: this used to read `hasFilter()`/`hasGroups()`/`hasExcludeGroups()`/
     * `includeTestSuites()`/`excludeTestSuites()` off `$this->configuration` — the MERGED
     * configuration, XML plus CLI. `Configuration\Merger` folds a project's own
     * `<groups><include>/<exclude>` and its `defaultTestSuite` into that merged object
     * whenever the CLI side is silent on them (`$groups = $cliConfiguration->hasGroups() ?
     * $cliConfiguration->groups() : $xmlConfiguration->groups()->include()->...`, and the
     * same shape for excludeGroups/includeTestSuite) — so a project whose `phpunit.xml`
     * excludes a group (a common way to quarantine tests that hit real external services)
     * reported a partial selection with ZERO command-line arguments: `record` refused
     * outright, and `run` never recorded a single edge, on every ordinary invocation.
     * XML-declared group/testsuite configuration is the project's own definition of what
     * "the whole suite" means, not a selection — SPEC.md §3.1 (and this method) mean the
     * COMMAND LINE specifically. `hasFilter()`/`hasExcludeFilter()`/`hasCliArguments()`
     * were never affected (PHPUnit's XML schema has no `<filter>` equivalent, and
     * `cliArguments()` is always `$cliConfiguration->arguments()` verbatim), so those three
     * are read from `$cliConfiguration` too, for the same reason, not because they needed
     * fixing.
     *
     * `$this->cliConfiguration === null` means the caller could not reliably determine the
     * CLI half at all (see {@see self::cliConfigurationFromArgv()}) — failed CLOSED, as a
     * selection, never open: the wrong direction would let an unrecognised context (a
     * Paratest worker's own internally-generated argv, an unparseable one, ...) replay or
     * record into the cache for what may be a deliberately narrowed run.
     */
    public function hasPartialSelection(): bool
    {
        $cli = $this->cliConfiguration;

        if ($cli === null) {
            return true;
        }

        return $cli->hasFilter()
            || $cli->hasExcludeFilter()
            || $cli->hasGroups()
            || $cli->hasExcludeGroups()
            || $cli->hasTestSuite()
            || $cli->hasExcludedTestSuite()
            || $cli->arguments() !== [];
    }

    /**
     * SPEC.md §6.2. `failOnAllIssues()`/`displayDetailsOnAllIssues()` are not folded into
     * the individual `failOn*()`/`displayDetailsOn*()` getters by `Configuration` itself
     * (verified against `ShellExitCodeCalculator::calculate()` and
     * `TextUI\Output\Facade`, which both OR them in by hand) so this method does the same.
     */
    public function shouldRerun(int $status): bool
    {
        $configuration = $this->configuration;
        $failOnAllIssues = $configuration->failOnAllIssues();
        $displayAllIssues = $configuration->displayDetailsOnAllIssues();

        return match ($status) {
            0 => false,
            1 => $configuration->failOnSkipped() || $configuration->displayDetailsOnSkippedTests()
                || $failOnAllIssues || $displayAllIssues,
            2 => $configuration->failOnIncomplete() || $configuration->displayDetailsOnIncompleteTests()
                || $failOnAllIssues || $displayAllIssues,
            3 => $configuration->failOnNotice() || $configuration->displayDetailsOnTestsThatTriggerNotices()
                || $failOnAllIssues || $displayAllIssues,
            4 => $configuration->failOnDeprecation() || $configuration->displayDetailsOnTestsThatTriggerDeprecations()
                || $failOnAllIssues || $displayAllIssues,
            5 => $configuration->failOnRisky() || $failOnAllIssues,
            6 => $configuration->failOnWarning() || $configuration->displayDetailsOnTestsThatTriggerWarnings()
                || $failOnAllIssues || $displayAllIssues,
            7, 8 => true,
            default => true,
        };
    }

    /** @return list<string> absolute directories */
    public function sourceIncludeDirectories(): array
    {
        $out = [];

        foreach ($this->configuration->source()->includeDirectories() as $directory) {
            $out[] = $directory->path();
        }

        return $out;
    }

    /** @return list<string> */
    public function testSuffixes(): array
    {
        return $this->configuration->testSuffixes();
    }

    /**
     * A `Filter` scoped to the same `<source>` include/exclude configuration PHPUnit's own
     * coverage collection uses (`PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry::
     * init()`), rebuilt independently rather than read off that registry (a process-wide
     * singleton this may run inside a process that never touched, per the same constraint
     * documented on {@see self::fromXmlFile()}). Used by `Report\CoverageMerger::writeEmptyRun()`
     * to build an empty `CodeCoverage` for a replay pass where nothing executed (SPEC.md §3.2
     * last paragraph).
     */
    public function emptyCoverageFilter(): Filter
    {
        $filter = new Filter();
        $source = $this->configuration->source();

        if ($source->notEmpty()) {
            $filter->includeFiles(array_keys((new SourceMapper())->map($source)));
        }

        return $filter;
    }

    public function configurationFile(): ?string
    {
        return $this->configuration->hasConfigurationFile() ? $this->configuration->configurationFile() : null;
    }

    /**
     * True when `--repeat`/`--retry` (PHPUnit 13.3+ only, docs/INTERNALS.md "hazard, real
     * and handled now that PHPUnit 13.1+ is supported") asked PHPUnit to run some test more
     * than once: from that version on, `PHPUnit\Event\Code\TestMethod::id()` appends
     * `' (repetition N of M)'`/`' (attempt N of M)'` to the id this package keys recorded
     * results on and looks up replay decisions by, so a run made under either flag is not
     * safe to replay from, nor to record into, the cache — {@see
     * \Manuglopez\Replay\Console\Runner\RunPipeline::degrade()} and {@see
     * \Manuglopez\Replay\PHPUnit\ReplayExtension::bootstrapInProcess()} both call this
     * before touching the graph.
     *
     * `Configuration::repeat()`/`retry()` do not exist at all before 13.3 (there is nothing
     * to be true here on 11.5, 12 or 13.0-13.2, and calling either directly would be a fatal
     * `Call to undefined method` on those installs) — guarded the same way, and for the same
     * PHPStan reason, as {@see self::intOption()}.
     */
    public function repeatOrRetryRequested(): bool
    {
        return $this->intOption('repeat') > 1 || $this->intOption('retry') > 1;
    }

    /**
     * `$getter` is received as a plain, non-literal `string` parameter on purpose:
     * {@see self::repeatOrRetryRequested()} calls this with a literal ('repeat', 'retry'),
     * but this method's own body only ever sees the widened `string` type, so PHPStan
     * cannot resolve the dynamic `$this->configuration->{$getter}()` call against whichever
     * PHPUnit version happens to be installed (its method-existence check for a dynamic
     * `$obj->{$var}()` call only fires when it can resolve `$var` to a literal string at
     * analysis time) and treats it as unverifiable — exactly what a method absent before
     * 13.3 needs: a literal-typed parameter would let PHPStan reconstruct the literal and
     * flag the call as `method.notFound` against an installed version that lacks it.
     */
    private function intOption(string $getter): int
    {
        if (! method_exists($this->configuration, $getter)) {
            return 1;
        }

        $value = $this->configuration->{$getter}();

        return is_int($value) ? $value : 1;
    }
}
