<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\CodeCoverage;

/**
 * SPEC.md §3.2 last paragraph, docs/INTERNALS.md "CoverageMerger", deliverable 4
 * (integration): drives the real `bin/phpunit-replay` wrapper against the `plain` fixture
 * with `--coverage-php` and shows that (1) a full record pass writes a snapshot per test
 * file, (2) a later pass that replays everything from cache still produces the same
 * coverage report by folding those snapshots together, and (3) a pass that DOES execute
 * something merges its own coverage with the snapshot of whatever it replayed.
 */
final class CoverageMergeTest extends TestCase
{
    /** @var list<string> */
    private const SRC_FILES = [
        'src/Cart.php',
        'src/Discount.php',
        'src/Greeter.php',
        'src/Money.php',
        'src/TaxCalculator.php',
    ];

    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_record_writes_a_snapshot_per_test_file(): void
    {
        $recorded = $this->fixture->replay(['record', '--', '--coverage-php=cov.php']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        self::assertFileExists($this->fixture->root() . '/cov.php');

        $stateDir = ReplayAssert::stateDir($this->fixture);
        $snapshots = glob($stateDir . '/coverage/*.cov') ?: [];
        self::assertCount(7, $snapshots, 'one .cov snapshot per test file of the plain fixture');
    }

    public function test_unchanged_run_folds_all_snapshots_into_an_equivalent_report(): void
    {
        $recorded = $this->fixture->replay(['record', '--', '--coverage-php=cov.php']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $lines1 = self::loadCoverage($this->fixture->root() . '/cov.php')->getData(true)->lineCoverage();

        $result = $this->fixture->replay(['--', '--coverage-php=cov2.php']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($result['stdout']), $result['stdout']);

        $lines2 = self::loadCoverage($this->fixture->root() . '/cov2.php')->getData(true)->lineCoverage();

        foreach (self::SRC_FILES as $relative) {
            $absolute = $this->fixture->root() . '/' . $relative;
            self::assertArrayHasKey($absolute, $lines2, $relative . ' missing from cov2.php');

            self::assertSame(
                self::executedLineNumbers($lines1[$absolute] ?? []),
                self::executedLineNumbers($lines2[$absolute] ?? []),
                $relative . ': executed line numbers differ between the recorded run and the merged replay',
            );
        }
    }

    public function test_a_real_run_still_merges_the_snapshot_of_what_it_did_not_execute(): void
    {
        $recorded = $this->fixture->replay(['record', '--', '--coverage-php=cov.php']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->fixture->applyVariant('Money.behaviour.php', 'src/Money.php');

        $result = $this->fixture->replay(['--', '--coverage-php=cov3.php']);
        self::assertSame(0, $result['exitCode'], $result['stdout'] . $result['stderr']);
        self::assertSame(31, ReplayAssert::executedCount($result['stdout']), $result['stdout']);

        $lines3 = self::loadCoverage($this->fixture->root() . '/cov3.php')->getData(true)->lineCoverage();

        $greeter = $this->fixture->root() . '/src/Greeter.php';
        self::assertArrayHasKey($greeter, $lines3, 'src/Greeter.php (never re-executed) should still come from its snapshot');
        self::assertNotSame([], self::executedLineNumbers($lines3[$greeter] ?? []));

        $money = $this->fixture->root() . '/src/Money.php';
        self::assertArrayHasKey($money, $lines3);
        self::assertNotSame([], self::executedLineNumbers($lines3[$money] ?? []));
    }

    private static function loadCoverage(string $path): CodeCoverage
    {
        $coverage = include $path;
        self::assertInstanceOf(CodeCoverage::class, $coverage);

        return $coverage;
    }

    /**
     * @param array<int, null|list<string>> $lines
     * @return list<int>
     */
    private static function executedLineNumbers(array $lines): array
    {
        $out = [];

        foreach ($lines as $line => $ids) {
            if (is_array($ids) && $ids !== []) {
                $out[] = $line;
            }
        }

        sort($out);

        return $out;
    }
}
