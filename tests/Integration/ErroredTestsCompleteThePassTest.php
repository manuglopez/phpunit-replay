<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * PHPUnit exits 2 as soon as one test *errors* (`ShellExitCodeCalculator`: `hasErrors()` →
 * `EXCEPTION_EXIT`), against 1 for a failure. The wrapper used to count only 0 and 1 as a
 * complete pass, so a suite with a single erroring test never finalized its baseline: the
 * layer was saved without a sha, the next pass found "no cached baseline" and recorded the
 * whole suite again, and so on for as long as that test errored. `record` still printed
 * `baseline <branch>@<sha>` for a baseline it had not saved.
 *
 * An error is an outcome like a failure: the test ran and its result was recorded. Only a
 * pass that did not run to the end (aborted, truncated, crashed) is incomplete.
 */
final class ErroredTestsCompleteThePassTest extends TestCase
{
    private const ERRORING_TEST = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Tests;

        use PHPUnit\Framework\TestCase;

        final class ErroringTest extends TestCase
        {
            public function testItThrows(): void
            {
                throw new \RuntimeException('an error, not a failure');
            }
        }
        PHP;

    private ?FixtureProject $fixture = null;

    protected function tearDown(): void
    {
        $this->fixture?->destroy();
        $this->fixture = null;

        parent::tearDown();
    }

    private function withAnErroringTest(): FixtureProject
    {
        $fixture = FixtureProject::plain();
        $this->fixture = $fixture;

        $fixture->write('tests/ErroringTest.php', self::ERRORING_TEST);
        $fixture->repo->git('add', '-A');
        $fixture->repo->git('commit', '-q', '-m', 'an erroring test');

        return $fixture;
    }

    public function test_a_record_with_an_erroring_test_finalizes_its_baseline(): void
    {
        $fixture = $this->withAnErroringTest();
        $head = trim($fixture->repo->git('rev-parse', 'HEAD'));

        $recorded = $fixture->replay(['record']);
        self::assertSame(2, $recorded['exitCode'], 'PHPUnit exits 2 for an erroring test: ' . $recorded['stdout'] . $recorded['stderr']);

        $graph = ReplayAssert::loadGraph($fixture);
        self::assertNotNull($graph);
        self::assertSame($head, $graph->ownRecordedSha('main'), 'the pass ran to the end, so its baseline is finalized at HEAD');
    }

    public function test_the_pass_after_an_erroring_record_replays_everything_but_the_error(): void
    {
        $fixture = $this->withAnErroringTest();
        $fixture->replay(['record']);

        $run = $fixture->replay();
        $out = $run['stdout'] . $run['stderr'];

        self::assertSame(2, $run['exitCode'], 'the erroring test runs again and still errors: ' . $out);
        self::assertStringNotContainsString('no cached baseline', $out);
        self::assertStringNotContainsString('recording a fresh baseline', $out);
        self::assertMatchesRegularExpression('/1 executed .* 35 replayed/', $out, 'only the errored file re-runs; ' . $out);
    }

    public function test_record_never_announces_a_baseline_it_did_not_save(): void
    {
        $fixture = FixtureProject::plain();
        $this->fixture = $fixture;

        // CI without --allow-ci-baseline saves results but never finalizes the baseline.
        $recorded = $fixture->replay(['record'], ['CI' => 'true']);
        $out = $recorded['stdout'] . $recorded['stderr'];

        self::assertSame(0, $recorded['exitCode'], $out);
        self::assertDoesNotMatchRegularExpression('/baseline main@[0-9a-f]{7}/', $out, 'no sha was saved, so none is printed: ' . $out);
    }
}
