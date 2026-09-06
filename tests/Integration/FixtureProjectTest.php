<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class FixtureProjectTest extends TestCase
{
    /** @var list<FixtureProject> */
    private array $fixtures = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        $this->fixtures = [];
    }

    public function testPlainCopyIsAFreshGitRepositoryWithOneCommit(): void
    {
        $fixture = $this->plain();

        self::assertDirectoryExists($fixture->root() . '/.git');
        self::assertSame('main', $fixture->repo->branch());

        $log = trim($fixture->repo->git('log', '--format=%s'));
        self::assertSame('initial', $log);
    }

    public function testSuiteRunsGreenWithOneSkippedTest(): void
    {
        $fixture = $this->plain();

        $result = $fixture->phpunit();

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $result['stdout']);
    }

    public function testFixtureFailEnvironmentVariableForcesExactlyOneFailure(): void
    {
        $fixture = $this->plain();

        $result = $fixture->phpunit([], ['FIXTURE_FAIL' => '1']);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('FIXTURE_FAIL', $result['stdout']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Failures: 1, Skipped: 1.', $result['stdout']);
    }

    public function testCommentOnlyVariantStaysLintCleanAndGreen(): void
    {
        $fixture = $this->plain();
        $fixture->applyVariant('Money.comment-only.php', 'src/Money.php');

        $lint = new Process(['php', '-l', $fixture->root() . '/src/Money.php']);
        $lint->run();
        self::assertTrue($lint->isSuccessful(), $lint->getErrorOutput());

        $result = $fixture->phpunit();
        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Tests: 35, Assertions: 61, Skipped: 1.', $result['stdout']);
    }

    public function testBrokenDiscountVariantProducesExactlyOneFailure(): void
    {
        $fixture = $this->plain();
        $fixture->applyVariant('Discount.broken.php', 'src/Discount.php');

        $result = $fixture->phpunit();

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString(
            '1) App\Tests\DiscountTest::testFlatDiscountLargerThanPriceClampsToZero',
            $result['stdout'],
        );
        self::assertStringContainsString('Tests: 35, Assertions: 61, Failures: 1, Skipped: 1.', $result['stdout']);
    }

    private function plain(): FixtureProject
    {
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;

        return $fixture;
    }
}
