<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;

/**
 * `verify`'s `would replay` figure (SPEC.md §12.2) means: of the tests this pass executed
 * for real, how many a `run` on this same tree would have served from cache instead. That
 * makes it a property of the tree, decided off the run list `run` itself builds
 * (`Select\ReplaySet`, `RunPipeline::replaySetBeforeVerify()`) against the state as it was
 * before the pass started.
 *
 * It used to be decided by comparing each STORED content key against one recomputed from
 * coverage re-observed on the same pass. Since `verify` re-records edges and
 * `Graph::unionEdges()` only ever grows a test file's dependency set, any test that gained
 * an edge got a new key and silently dropped out of the count. The figure therefore
 * answered "is this test's dependency set byte-identical to the last recording?" and
 * drifted with how many passes had run: on a real 9056-test suite `9056 − would replay`
 * fell 2001 → 1941 → 1502 → 1253 across four identical passes, never converging, while
 * `run` on the same unchanged tree replayed 9056 of 9056.
 *
 * The four tests below pin both failure directions of that old measurement and the
 * granularity of the new one. Measured against the pre-fix code, for the record:
 *
 * | scenario                           | before           | after         |
 * |------------------------------------|------------------|---------------|
 * | two identical passes (`keydrift`)  | 2 then 4         | 4 then 4      |
 * | a changed watched file (`.env`)    | 4 (over-counts)  | 0             |
 * | a changed source file              | 2                | 2 (unchanged) |
 * | moved key + a changed source file  | 0 (under-counts) | 2             |
 */
final class VerifyWouldReplayMatchesTheRunListTest extends TestCase
{
    /** tests/Fixtures/Projects/keydrift: CoreTest and OtherTest, two tests each. */
    private const TOTAL_TESTS = 4;

    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = FixtureProject::keyDrift();
    }

    protected function tearDown(): void
    {
        $this->fixture->destroy();
    }

    /**
     * The reproduction, and the acceptance test the whole change exists for: two identical
     * `verify` passes over a tree in which not one byte changed must report the same
     * `would replay`.
     *
     * `FIXTURE_EXTRA_EDGE=1` makes `CoreTest` execute `src/Extra.php`, which the baseline
     * `record` never saw — so the first `verify` observes a dependency set the graph did not
     * have, unions it in, and moves that file's content key. Before the fix the first pass
     * reported `4 tests · 2 would replay` (both of `CoreTest`'s tests dropped for having a
     * moved key) and the second `4 tests · 4 would replay`, from identical inputs. The
     * figure is now the same on both passes, because nothing a `run` would look at changed:
     * the tree is clean, so the run list is empty and all four tests replay.
     *
     * What legitimately differs between the passes is `unverified` — the part of
     * `would replay` this pass could not check, because the cached results were recorded
     * against a different dependency set. That is a statement about each pass's own
     * evidence, and 2-then-0 is it working: the graph settles, and the caveat retires.
     */
    public function test_would_replay_is_the_same_across_two_identical_verify_passes(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $first = $this->fixture->replay(['verify'], ['FIXTURE_EXTRA_EDGE' => '1']);
        $second = $this->fixture->replay(['verify'], ['FIXTURE_EXTRA_EDGE' => '1']);

        self::assertSame(0, $first['exitCode'], $first['stdout'] . $first['stderr']);
        self::assertSame(0, $second['exitCode'], $second['stdout'] . $second['stderr']);

        // The one assertion the fix is about: same tree, same command, same number.
        self::assertSame(
            self::wouldReplay($second),
            self::wouldReplay($first),
            "Two identical verify passes disagreed on 'would replay'.\n"
            . 'first:  ' . self::verifyLine($first) . "\n"
            . 'second: ' . self::verifyLine($second),
        );

        self::assertSame(self::TOTAL_TESTS, self::wouldReplay($first), self::verifyLine($first));
        self::assertStringContainsString(
            self::TOTAL_TESTS . ' tests · ' . self::TOTAL_TESTS . ' would replay · 0 divergences',
            $first['stdout'],
        );

        // The moved keys are reported rather than absorbed: both of CoreTest's tests on the
        // pass that moved them, none on the pass after.
        self::assertSame(2, self::unverified($first), self::verifyLine($first));
        self::assertSame(0, self::unverified($second), self::verifyLine($second));
    }

    /**
     * The other direction the old measurement was wrong in. A `.env` file is a default watch
     * pattern (`Select\WatchDefaults\Php`) mapped to the test directory, so creating one puts
     * every test file in the run list — a `run` here replays nothing at all. No content key
     * moves, though (`.env` is not a coverage edge and so not part of any test file's
     * dependency list), so the old key-comparison happily reported all four tests as
     * "would replay" — over-counting the fast lane's coverage on exactly the kind of change
     * that suppresses it.
     */
    public function test_a_watched_file_change_drops_the_tests_it_selects_from_would_replay(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->fixture->write('.env', "APP_ENV=testing\n");

        $verify = $this->fixture->replay(['verify']);

        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);
        self::assertSame(self::TOTAL_TESTS, self::tests($verify), self::verifyLine($verify));
        self::assertSame(0, self::wouldReplay($verify), self::verifyLine($verify));
        self::assertSame(0, self::unverified($verify), self::verifyLine($verify));
    }

    /**
     * The granularity: a changed source file drops the tests that depend on it and nobody
     * else. Without this, `would replay` could degenerate into "every test that ran" and
     * still satisfy the stability test above.
     *
     * `src/Core.php` is rewritten behaviour-preservingly, so `CoreTest`'s two tests are
     * affected (and re-executed by a `run`) while `OtherTest`'s two are untouched. This one
     * held before the fix too — a changed source file moves the dependent test file's
     * content key as well — so it is here to pin the meaning, not to catch the bug.
     */
    public function test_a_changed_source_file_drops_only_the_tests_that_depend_on_it(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->fixture->write('src/Core.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Core
        {
            public function double(int $value): int
            {
                $doubled = $value * 2;

                return $doubled;
            }
        }

        PHP);

        $verify = $this->fixture->replay(['verify']);

        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);
        self::assertSame(self::TOTAL_TESTS, self::tests($verify), self::verifyLine($verify));
        self::assertSame(2, self::wouldReplay($verify), self::verifyLine($verify));
    }

    /**
     * Both exclusion mechanisms at once, which is also where the two new figures sit at
     * their tightest: `CoreTest`'s keys move (`FIXTURE_EXTRA_EDGE`) while `OtherTest`'s
     * source is edited on disk. A `run` here replays `CoreTest`'s two tests and re-executes
     * `OtherTest`'s two, and this pass cannot vouch for either of the two it would have
     * replayed — `4 tests · 2 would replay · 2 unverified`, i.e. `unverified == wouldReplay`
     * at the bound. Before the fix this reported `0 would replay`: all four had a moved key,
     * `CoreTest`'s from the extra edge and `OtherTest`'s from the edit, so the key comparison
     * excluded the entire suite and the figure claimed the fast lane would cover none of it —
     * while a `run` on that tree would in fact have replayed half.
     */
    public function test_a_key_that_moved_and_a_source_change_are_counted_separately(): void
    {
        $recorded = $this->fixture->replay(['record']);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->fixture->write('src/Other.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        final class Other
        {
            public function half(int $value): int
            {
                $halved = intdiv($value, 2);

                return $halved;
            }
        }

        PHP);

        $verify = $this->fixture->replay(['verify'], ['FIXTURE_EXTRA_EDGE' => '1']);

        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);

        $line = self::verifyLine($verify);
        $tests = self::tests($verify);
        $wouldReplay = self::wouldReplay($verify);
        $unverified = self::unverified($verify);

        self::assertSame(self::TOTAL_TESTS, $tests, $line);
        self::assertSame(2, $wouldReplay, $line);
        self::assertSame(2, $unverified, $line);

        // The invariants the summary line must never be allowed to break.
        self::assertLessThanOrEqual($wouldReplay, $unverified, $line);
        self::assertLessThanOrEqual($tests, $wouldReplay, $line);
    }

    /** @param array{stdout: string, stderr: string, exitCode: int} $result */
    private static function verifyLine(array $result): string
    {
        foreach (array_reverse(explode("\n", $result['stdout'])) as $candidate) {
            if (str_contains($candidate, 'would replay')) {
                return trim($candidate);
            }
        }

        self::fail("No verify summary line in:\n" . $result['stdout'] . $result['stderr']);
    }

    /** @param array{stdout: string, stderr: string, exitCode: int} $result */
    private static function tests(array $result): int
    {
        return self::figure($result, 'tests');
    }

    /** @param array{stdout: string, stderr: string, exitCode: int} $result */
    private static function wouldReplay(array $result): int
    {
        return self::figure($result, 'would replay');
    }

    /** @param array{stdout: string, stderr: string, exitCode: int} $result */
    private static function unverified(array $result): int
    {
        return self::figure($result, 'unverified');
    }

    /** @param array{stdout: string, stderr: string, exitCode: int} $result */
    private static function figure(array $result, string $label): int
    {
        $line = self::verifyLine($result);

        if (preg_match('/(\d+) ' . preg_quote($label, '/') . '/', $line, $matches) !== 1) {
            self::fail(sprintf('No "%s" figure in the verify summary line: %s', $label, $line));
        }

        return (int) $matches[1];
    }
}
