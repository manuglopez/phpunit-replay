<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use PHPUnit\Framework\TestCase;

/**
 * Seen in a real CI log: `phpunit-replay record --fresh -p --allow-ci-baseline` died with
 * `Unknown option "--allow-ci-baseline"` (the wrapper had forwarded it to PHPUnit), although
 * `record` is exactly the command CI uses to seed a baseline. `record` now takes the option
 * with `run`'s semantics; `verify` stays a pure measurement and does not.
 */
final class RecordAllowCiBaselineTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::plain();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    public function test_a_ci_record_with_allow_ci_baseline_finalizes_and_publishes_the_baseline(): void
    {
        $head = trim($this->fixture->repo->git('rev-parse', 'HEAD'));

        $recorded = $this->fixture->replay(['record', '--allow-ci-baseline'], ['CI' => 'true']);
        $out = $recorded['stdout'] . $recorded['stderr'];

        self::assertSame(0, $recorded['exitCode'], $out);
        self::assertStringNotContainsString('Unknown option', $out);
        self::assertStringNotContainsString('CI detected', $out);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertSame($head, $graph->ownRecordedSha('main'));
    }

    public function test_a_ci_record_without_it_still_publishes_no_baseline(): void
    {
        $recorded = $this->fixture->replay(['record'], ['CI' => 'true']);
        $out = $recorded['stdout'] . $recorded['stderr'];

        self::assertSame(0, $recorded['exitCode'], $out);
        self::assertStringContainsString('CI detected', $out);

        $graph = ReplayAssert::loadGraph($this->fixture);
        self::assertNotNull($graph);
        self::assertNull($graph->ownRecordedSha('main'));
    }

    public function test_a_misplaced_wrapper_option_fails_fast_and_never_reaches_phpunit(): void
    {
        $result = $this->fixture->replay(['verify', '--allow-ci-baseline']);
        $out = $result['stdout'] . $result['stderr'];

        self::assertSame(2, $result['exitCode'], $out);
        self::assertStringContainsString('phpunit-replay: --allow-ci-baseline is an option of "run" and "record", not of "verify"', $out);
        self::assertStringNotContainsString('PHPUnit', $out);
        self::assertStringNotContainsString('Unknown option', $out);
        self::assertNull(ReplayAssert::loadGraph($this->fixture));
    }
}
