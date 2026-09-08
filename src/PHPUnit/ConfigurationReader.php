<?php

declare(strict_types=1);

namespace Manuglopez\Replay\PHPUnit;

use PHPUnit\TextUI\CliArguments\Builder as CliArgumentsBuilder;
use PHPUnit\TextUI\Configuration\Configuration;
use PHPUnit\TextUI\Configuration\Merger;
use PHPUnit\TextUI\Configuration\SourceMapper;
use PHPUnit\TextUI\XmlConfiguration\Loader as XmlConfigurationLoader;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * Thin, version-tolerant reads of a PHPUnit `Configuration` object: everything that
 * differs between PHPUnit 11.5, 12 and 13 (e.g. the test suite name getters, singular-only
 * in 11.5, both in 12, plural-only in 13) is abstracted here behind `method_exists` checks,
 * per docs/INTERNALS.md.
 */
final readonly class ConfigurationReader
{
    public function __construct(private Configuration $configuration)
    {
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

        return new self($configuration);
    }

    /**
     * True when the user asked PHPUnit to run something narrower than the full suite
     * (`--filter`, `--exclude-filter`, `--group`, `--exclude-group`, `--testsuite`,
     * `--exclude-testsuite`, or an explicit path/file argument). SPEC.md §3.1.
     */
    public function hasPartialSelection(): bool
    {
        $configuration = $this->configuration;

        return $configuration->hasFilter()
            || $configuration->hasExcludeFilter()
            || $configuration->hasGroups()
            || $configuration->hasExcludeGroups()
            || $this->includeTestSuites() !== []
            || $this->excludeTestSuites() !== []
            || $configuration->hasCliArguments();
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
     * PHPStan reason, as {@see self::testSuiteNames()}.
     */
    public function repeatOrRetryRequested(): bool
    {
        return $this->intOption('repeat') > 1 || $this->intOption('retry') > 1;
    }

    /** @return list<string> */
    private function includeTestSuites(): array
    {
        return $this->testSuiteNames('includeTestSuite', 'includeTestSuites');
    }

    /** @return list<string> */
    private function excludeTestSuites(): array
    {
        return $this->testSuiteNames('excludeTestSuite', 'excludeTestSuites');
    }

    /**
     * The three supported PHPUnit majors expose the configured test suite names differently:
     * 11.5 has only the singular, comma-joined `includeTestSuite()` / `excludeTestSuite()`;
     * 12 adds the plural, list-returning `includeTestSuites()` / `excludeTestSuites()` while
     * keeping the singular ones as deprecated aliases; 13 removed the singular ones, so calling
     * them there is a fatal `Call to undefined method`. In 12 the plural methods are implemented
     * as `$value === '' ? [] : explode(',', $value)` over that same string, so preferring the
     * plural when present and splitting the singular ourselves otherwise yields the identical
     * result on all three.
     *
     * The dispatch goes through variable method names on purpose, and both names are received
     * as plain, non-literal `string` parameters (never built from a literal via concatenation,
     * and never narrowed by a `@param 'a'|'b'` union): PHPStan's method-existence check for a
     * dynamic `$obj->{$var}()` call only fires when it can resolve `$var` to a literal string
     * (or a union of them) at analysis time. A literal `method_exists($this->configuration,
     * 'includeTestSuites')` guard — or a literal-typed parameter that lets PHPStan reconstruct
     * one via concatenation — is exactly that: PHPStan then evaluates the check itself against
     * whichever version happens to be installed and flags whichever of the two branches is
     * dead code (or, if it instead trusts the branch that's live, flags the call to the method
     * absent from the installed version as `method.notFound` — verified: it's the latter
     * against PHPUnit 13, where the singular methods no longer exist at all). Keeping both
     * parameters as opaque strings denies PHPStan that literal to resolve against, so the
     * dynamic call is (correctly) treated as unverifiable rather than statically checked
     * against only the one version installed at analysis time.
     *
     * @return list<string>
     */
    private function testSuiteNames(string $singular, string $plural): array
    {
        $getter = method_exists($this->configuration, $plural) ? $plural : $singular;

        /** @var list<string>|string $value */
        $value = $this->configuration->{$getter}();

        return is_array($value) ? array_values($value) : self::splitTestSuiteNames($value);
    }

    /**
     * `$getter` is received as a plain, non-literal `string` parameter for the same reason
     * documented on {@see self::testSuiteNames()}: {@see self::repeatOrRetryRequested()}
     * calls this with a literal ('repeat', 'retry'), but this method's own body only ever
     * sees the widened `string` type, so PHPStan cannot resolve the dynamic
     * `$this->configuration->{$getter}()` call against whichever PHPUnit version happens to
     * be installed and treats it as unverifiable — exactly what a method absent before 13.3
     * needs.
     */
    private function intOption(string $getter): int
    {
        if (! method_exists($this->configuration, $getter)) {
            return 1;
        }

        $value = $this->configuration->{$getter}();

        return is_int($value) ? $value : 1;
    }

    /** @return list<string> */
    private static function splitTestSuiteNames(string $value): array
    {
        return $value === '' ? [] : explode(',', $value);
    }
}
