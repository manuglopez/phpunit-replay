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
 * differs between PHPUnit 11.5 and 12 (e.g. `includeTestSuites()` only existing since
 * 12, with 11.5 exposing the single-string `includeTestSuite()`) is abstracted here
 * behind `method_exists` checks, per docs/INTERNALS.md.
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
     * PHPUnit 12 exposes plural, list-returning `includeTestSuites()` / `excludeTestSuites()`;
     * PHPUnit 11.5 only has the single, comma-joined `includeTestSuite()` / `excludeTestSuite()`
     * (in 12 these are kept as deprecated aliases and the plural methods are themselves
     * implemented as `$value === '' ? [] : explode(',', $value)` over the very same string —
     * see `PHPUnit\TextUI\Configuration\Configuration::includeTestSuites()`). Reading only the
     * singular getters and doing that split ourselves therefore reproduces PHPUnit 12's own
     * behaviour exactly, on both versions, without ever calling a method absent from 11.5 —
     * so there is nothing here for PHPStan to narrow to "always true/false" against either
     * installed version, unlike a `method_exists()` guard on the plural name would be.
     *
     * @return list<string>
     */
    private function includeTestSuites(): array
    {
        return self::splitTestSuiteNames($this->configuration->includeTestSuite());
    }

    /** @return list<string> */
    private function excludeTestSuites(): array
    {
        return self::splitTestSuiteNames($this->configuration->excludeTestSuite());
    }

    /** @return list<string> */
    private static function splitTestSuiteNames(string $value): array
    {
        return $value === '' ? [] : explode(',', $value);
    }
}
