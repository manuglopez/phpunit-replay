<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Console;

use Manuglopez\Replay\Console\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Characterizes `Application::splitPassthrough()` — the argv pre-splitter that decides,
 * token by token, whether a CLI argument belongs to phpunit-replay itself or should be
 * forwarded to PHPUnit/Paratest after an inserted `--`. Every expected value below was
 * produced by RUNNING the implementation this corpus was first written against (the
 * previous, hand-maintained-constants one, via `ReflectionMethod` since `splitPassthrough()`
 * is private) and then read back by hand to confirm it is what the CLI should do — see
 * "PINNED, DISCLOSED GAPS" below for the cases where that reading concluded "this looks
 * wrong, but is out of scope to fix here".
 *
 * This corpus is what made the splitter's rewrite safe: `splitPassthrough()` no longer
 * consults five hand-maintained constants (`COMMAND_NAMES`, `OWN_LONG_OPTIONS`,
 * `GLOBAL_LONG_OPTIONS`, `GLOBAL_SHORT_OPTIONS`, `PARALLEL_SHORTCUT_COMMANDS`) — it derives
 * ownership from the live Symfony `InputDefinition` objects instead — and every case here
 * passes, byte-for-byte, against BOTH implementations.
 *
 * PINNED, DISCLOSED GAPS (today's actual behaviour, deliberately not fixed by this corpus
 * or by the rewrite it made safe — behavioural equivalence was the bar):
 *
 * - `--log-junit`/`--keep-months` are recognised ONLY in their `--option=value` form. The
 *   bare form — `--log-junit result.xml` as two tokens — is NOT recognised: the first token
 *   alone trips the passthrough latch and takes the value token with it. Real Symfony's own
 *   `ArgvInput::addLongOption()` would peek the next token as the value for a bare
 *   `VALUE_REQUIRED` option exactly the way it does for `--parallel`, so this asymmetry
 *   looks like a genuine defect rather than a deliberate choice — reported as such, pinned
 *   as-is here.
 * - `-p=2` (short form, literal `=`) is NOT recognised, even though `-p`, `-p 2` (two
 *   tokens), `--parallel`, `--parallel 2` and `--parallel=2` all are, and both SPEC.md and
 *   README document the option as `-p[=N]`. This looks like a second, independent instance
 *   of the same class of defect — reported separately, pinned as-is here.
 * - `-p4` / `-p10` (a process count glued directly onto the shortcut, no `=`) WAS
 *   recognised by the previous implementation, via a regex (`/^p\d*$/`) the rewrite
 *   deliberately does not reproduce (see `Application::splitPassthrough()`'s docblock): it
 *   was never part of this corpus for exactly that reason — it was expected to change, not
 *   pinned, and now does not recognise these tokens (see the dedicated regression cases at
 *   the bottom of {@see self::cases()}, added once the rewrite landed).
 * - Clustering (`-xyz`) is not implemented; a clustered token is simply not recognised
 *   (goes to passthrough, tripping the latch like any other unrecognised token).
 */
final class ApplicationArgvSplitTest extends TestCase
{
    /**
     * `splitPassthrough()` is now an instance method (it reads the live command/application
     * `InputDefinition` objects via `$this`), so — unlike against the previous,
     * hand-maintained-constants implementation this corpus was first generated against — it
     * is invoked here on a real `Application` instance rather than statically. This is the
     * only thing about this file that changed between the two implementations: every case
     * in {@see self::cases()} is unchanged.
     *
     * @param list<string> $argv
     * @return list<string>
     */
    private static function split(array $argv): array
    {
        $method = (new ReflectionClass(Application::class))->getMethod('splitPassthrough');
        $method->setAccessible(true);

        /** @var list<string> $result */
        $result = $method->invoke(new Application(), $argv);

        return $result;
    }

    /**
     * @param list<string> $argv
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('cases')]
    public function it_splits_argv_the_way_the_current_implementation_does(array $argv, array $expected): void
    {
        self::assertSame($expected, self::split($argv));
    }

    /** @return iterable<string, array{0: list<string>, 1: list<string>}> */
    public static function cases(): iterable
    {
        // --- run: every own long option, bare form -------------------------------------
        yield 'run --fresh (bare)' => [['run', '--fresh'], ['run', '--fresh']];
        yield 'run --no-remote (bare)' => [['run', '--no-remote'], ['run', '--no-remote']];
        yield 'run --explain (bare)' => [['run', '--explain'], ['run', '--explain']];
        yield 'run --dry-run (bare)' => [['run', '--dry-run'], ['run', '--dry-run']];
        yield 'run --allow-ci-baseline (bare)' => [['run', '--allow-ci-baseline'], ['run', '--allow-ci-baseline']];
        yield 'run --in-process (bare)' => [['run', '--in-process'], ['run', '--in-process']];
        yield 'run --filtered (bare)' => [['run', '--filtered'], ['run', '--filtered']];

        // --- run --log-junit: the =value special case, and its pinned bare-form gap ----
        yield 'run --log-junit=value (recognised)' => [['run', '--log-junit=x.xml'], ['run', '--log-junit=x.xml']];
        yield 'run --log-junit bare, no value at all (pinned gap: NOT recognised)' => [
            ['run', '--log-junit'],
            ['run', '--', '--log-junit'],
        ];
        yield 'run --log-junit value as two tokens (pinned gap: NOT recognised, value goes with it)' => [
            ['run', '--log-junit', 'x.xml'],
            ['run', '--', '--log-junit', 'x.xml'],
        ];

        // --- record: its one own long option besides --parallel ------------------------
        yield 'record --fresh (bare)' => [['record', '--fresh'], ['record', '--fresh']];

        // --- prune: every own long option, bare form ------------------------------------
        yield 'prune --flaky (bare)' => [['prune', '--flaky'], ['prune', '--flaky']];
        yield 'prune --branches (bare)' => [['prune', '--branches'], ['prune', '--branches']];
        yield 'prune --all (bare)' => [['prune', '--all'], ['prune', '--all']];
        yield 'prune --stale-edges (bare)' => [['prune', '--stale-edges'], ['prune', '--stale-edges']];
        yield 'prune --remote (bare)' => [['prune', '--remote'], ['prune', '--remote']];
        yield 'prune --squash (bare)' => [['prune', '--squash'], ['prune', '--squash']];

        // --- prune --keep-months: the =value special case, and its pinned bare-form gap
        yield 'prune --keep-months=value (recognised)' => [
            ['prune', '--keep-months=6'],
            ['prune', '--keep-months=6'],
        ];
        yield 'prune --keep-months bare, no value at all (pinned gap: NOT recognised)' => [
            ['prune', '--keep-months'],
            ['prune', '--', '--keep-months'],
        ];
        yield 'prune --keep-months value as two tokens (pinned gap: NOT recognised, value goes with it)' => [
            ['prune', '--keep-months', '6'],
            ['prune', '--', '--keep-months', '6'],
        ];
        yield 'prune --remote --keep-months=6 --squash (mixed own options, one value-mode)' => [
            ['prune', '--remote', '--keep-months=6', '--squash'],
            ['prune', '--remote', '--keep-months=6', '--squash'],
        ];

        // --- push: its one own long option -----------------------------------------------
        yield 'push --graph (bare)' => [['push', '--graph'], ['push', '--graph']];

        // --- --parallel/-p in every documented form, on each of the three commands that
        // declare it. `-p=2` is the pinned gap described in the class docblock: SPEC.md and
        // README document `-p[=N]`, but this form is not actually recognised today.
        foreach (['run', 'record', 'verify'] as $command) {
            yield "$command -p (bare)" => [[$command, '-p'], [$command, '-p']];
            yield "$command -p 2 (two tokens, peeked value)" => [[$command, '-p', '2'], [$command, '-p', '2']];
            yield "$command -p=2 (pinned gap: NOT recognised)" => [
                [$command, '-p=2'],
                [$command, '--', '-p=2'],
            ];
            yield "$command --parallel (bare)" => [[$command, '--parallel'], [$command, '--parallel']];
            yield "$command --parallel 2 (two tokens, peeked value)" => [
                [$command, '--parallel', '2'],
                [$command, '--parallel', '2'],
            ];
            yield "$command --parallel=2 (attached value)" => [
                [$command, '--parallel=2'],
                [$command, '--parallel=2'],
            ];
        }

        // --- -p on commands that do NOT declare it: must fall to passthrough ------------
        yield 'status -p (status does not declare -p)' => [['status', '-p'], ['status', '--', '-p']];
        yield 'prune -p (prune does not declare -p)' => [['prune', '-p'], ['prune', '--', '-p']];

        // --- PHPUnit-only flag alone, and mixed own/PHPUnit ordering, including the latch:
        // an own option AFTER an unrecognised token must still end up in passthrough,
        // because the latch (Application.php:160-163) is tripped by the FIRST unrecognised
        // token and never re-examines anything that follows it.
        yield 'run --filter=Foo alone' => [['run', '--filter=Foo'], ['run', '--', '--filter=Foo']];
        yield 'run --fresh then --filter=Foo (own, then passthrough)' => [
            ['run', '--fresh', '--filter=Foo'],
            ['run', '--fresh', '--', '--filter=Foo'],
        ];
        yield 'run --filter=Foo then --fresh (LATCH: the later own option also goes to passthrough)' => [
            ['run', '--filter=Foo', '--fresh'],
            ['run', '--', '--filter=Foo', '--fresh'],
        ];
        yield 'run --fresh, --filter=Foo, --dry-run (own, passthrough, and a later own option all caught by the latch)' => [
            ['run', '--fresh', '--filter=Foo', '--dry-run'],
            ['run', '--fresh', '--', '--filter=Foo', '--dry-run'],
        ];
        yield 'record --fresh then --filter=Foo' => [
            ['record', '--fresh', '--filter=Foo'],
            ['record', '--fresh', '--', '--filter=Foo'],
        ];
        yield 'verify --parallel then --filter=Foo' => [
            ['verify', '--parallel', '--filter=Foo'],
            ['verify', '--parallel', '--', '--filter=Foo'],
        ];
        yield 'verify --filter=Foo then --parallel (LATCH)' => [
            ['verify', '--filter=Foo', '--parallel'],
            ['verify', '--', '--filter=Foo', '--parallel'],
        ];
        yield 'verify --parallel=4 then --filter=Foo' => [
            ['verify', '--parallel=4', '--filter=Foo'],
            ['verify', '--parallel=4', '--', '--filter=Foo'],
        ];

        // --- commands with no `phpunit-args` slot: the splitter still inserts `--` and
        // forwards regardless of whether the command has anywhere to put it (this is the
        // exact shape of the historical prune bug: the splitter's job stops at producing
        // the token list, it does not know or care whether the command downstream can
        // accept what follows).
        foreach (['status', 'baseline-path', 'push', 'pull', 'prune'] as $command) {
            yield "$command alone (no passthrough inserted when nothing follows)" => [[$command], [$command]];
            yield "$command --filter=Foo (PHPUnit-only flag alone)" => [
                [$command, '--filter=Foo'],
                [$command, '--', '--filter=Foo'],
            ];
        }

        // --- explicit `--` already present in the input ---------------------------------
        yield 'run -- with nothing after it (the empty passthrough section is dropped entirely)' => [
            ['run', '--'],
            ['run'],
        ];
        yield 'run -- --fresh (already in passthrough, not re-recognised as an own option)' => [
            ['run', '--', '--fresh'],
            ['run', '--', '--fresh'],
        ];
        yield 'run --fresh -- --filter=Foo (own option, explicit boundary, then passthrough)' => [
            ['run', '--fresh', '--', '--filter=Foo'],
            ['run', '--fresh', '--', '--filter=Foo'],
        ];
        yield 'prune -- with nothing after it (dropped entirely)' => [['prune', '--'], ['prune']];

        // --- explain <path>: the one positional-argument special case -------------------
        yield 'explain <path> (recognised as the positional, not passthrough)' => [
            ['explain', 'src/Foo.php'],
            ['explain', 'src/Foo.php'],
        ];
        yield 'explain -weird-but-a-path (leading "-" excludes it from the positional special case)' => [
            ['explain', '-src/Foo.php'],
            ['explain', '--', '-src/Foo.php'],
        ];
        yield 'explain with no path at all' => [['explain'], ['explain']];
        yield 'explain --filter=Foo (a flag instead of a path; not own, not the positional)' => [
            ['explain', '--filter=Foo'],
            ['explain', '--', '--filter=Foo'],
        ];
        yield 'explain <path> extra (only one positional; the second token is passthrough)' => [
            ['explain', 'src/Foo.php', 'extra'],
            ['explain', 'src/Foo.php', '--', 'extra'],
        ];
        yield 'explain --filter=Foo <path> (latch trips first; the path never reaches the positional case)' => [
            ['explain', '--filter=Foo', 'src/Foo.php'],
            ['explain', '--', '--filter=Foo', 'src/Foo.php'],
        ];

        // --- global options, bare, on `run` ---------------------------------------------
        yield 'run --help' => [['run', '--help'], ['run', '--help']];
        yield 'run -h' => [['run', '-h'], ['run', '-h']];
        yield 'run --version' => [['run', '--version'], ['run', '--version']];
        yield 'run -V' => [['run', '-V'], ['run', '-V']];
        yield 'run --quiet' => [['run', '--quiet'], ['run', '--quiet']];
        yield 'run -q' => [['run', '-q'], ['run', '-q']];
        yield 'run --no-interaction' => [['run', '--no-interaction'], ['run', '--no-interaction']];
        yield 'run -n' => [['run', '-n'], ['run', '-n']];
        yield 'run --ansi' => [['run', '--ansi'], ['run', '--ansi']];
        yield 'run --no-ansi' => [['run', '--no-ansi'], ['run', '--no-ansi']];
        yield 'run --verbose' => [['run', '--verbose'], ['run', '--verbose']];
        yield 'run -v' => [['run', '-v'], ['run', '-v']];
        yield 'run -vv' => [['run', '-vv'], ['run', '-vv']];
        yield 'run -vvv' => [['run', '-vvv'], ['run', '-vvv']];

        // --- global options are command-independent: also recognised on a command with no
        // own options at all.
        yield 'status --help (global option on a no-own-options command)' => [
            ['status', '--help'],
            ['status', '--help'],
        ];
        yield 'pull -q (global option on a no-own-options command)' => [['pull', '-q'], ['pull', '-q']];
        yield 'baseline-path --help (global option on a no-own-options command)' => [
            ['baseline-path', '--help'],
            ['baseline-path', '--help'],
        ];
        yield 'push -q (global option on a no-own-options command)' => [['push', '-q'], ['push', '-q']];

        // --- no command / unknown command / empty argv / empty-string token ------------
        yield 'empty argv (defaults to run, no args at all)' => [[], ['run']];
        yield 'a single empty-string token (defaults to run; not recognised, goes to passthrough)' => [
            [''],
            ['run', '--', ''],
        ];
        yield 'a bare PHPUnit flag with no command at all (implicit default run command)' => [
            ['--filter=Foo'],
            ['run', '--', '--filter=Foo'],
        ];
        yield 'an unknown command name (defaults to run; treated as an ordinary passthrough token)' => [
            ['bogus-command'],
            ['run', '--', 'bogus-command'],
        ];
        yield 'an unknown command name followed by a real own-looking option (both go to passthrough)' => [
            ['bogus-command', '--fresh'],
            ['run', '--', 'bogus-command', '--fresh'],
        ];
        yield 'list (Symfony\'s own built-in command, untouched)' => [['list'], ['list']];
        yield 'help (Symfony\'s own built-in command, untouched)' => [['help'], ['help']];
        yield 'help run (Symfony\'s own built-in command, untouched)' => [['help', 'run'], ['help', 'run']];

        // --- clustering is not implemented: a clustered short token is simply not
        // recognised, exactly like any other unrecognised token.
        yield 'run -hq (clustered short option; NOT recognised, no clustering support)' => [
            ['run', '-hq'],
            ['run', '--', '-hq'],
        ];

        // --- peek-vs-latch interactions: the bare -p peek only ever consumes a token that
        // does not itself look like an option; and consuming a peeked value does not affect
        // whether recognition continues normally afterwards.
        yield 'run -p followed immediately by an option-looking token (not consumed as -p\'s value; trips the latch)' => [
            ['run', '-p', '--filter=Foo'],
            ['run', '-p', '--', '--filter=Foo'],
        ];
        yield 'run -p followed by an unrelated global shortcut (both recognised independently, no passthrough)' => [
            ['run', '-p', '-q'],
            ['run', '-p', '-q'],
        ];
        yield 'record -p 2 --fresh (peeked value does not disturb recognition of what follows)' => [
            ['record', '-p', '2', '--fresh'],
            ['record', '-p', '2', '--fresh'],
        ];

        // --- a VALUE_NONE option given an attached value: the splitter does not validate
        // value-acceptance, it only decides ownership (real Symfony's ArgvInput would later
        // reject this at bind time; that is unaffected by this corpus).
        yield 'run --fresh=1 (value attached to a flag that takes none; still recognised as own)' => [
            ['run', '--fresh=1'],
            ['run', '--fresh=1'],
        ];

        // --- falsy-string edge cases: PHP's "is this empty/falsy" gotchas must not misfire
        // the passthrough decision.
        yield 'run followed by the string "0" (not option-shaped; goes to passthrough)' => [
            ['run', '0'],
            ['run', '--', '0'],
        ];
        yield 'run followed by an empty string (goes to passthrough)' => [
            ['run', ''],
            ['run', '--', ''],
        ];
        yield 'run --fresh followed by an empty string (own option, then passthrough)' => [
            ['run', '--fresh', ''],
            ['run', '--fresh', '--', ''],
        ];

        // ---------------------------------------------------------------------------
        // NEW cases below, added once the derivation-based rewrite landed: these
        // document behaviour that CHANGED, not behaviour pinned from before it. They
        // were never part of the frozen corpus above (which passes unchanged against
        // both implementations) — see the class docblock's "PINNED, DISCLOSED GAPS".
        // ---------------------------------------------------------------------------

        // `--silent` is a real global option (Symfony 8.1's own `getDefaultInputDefinition()`)
        // that the previous hand-maintained `GLOBAL_LONG_OPTIONS` constant never listed —
        // confirmed missing from it while this corpus was written — so it used to be
        // misrouted to PHPUnit passthrough. Deriving from the live application definition
        // fixes this automatically, on whichever Symfony version is actually installed.
        yield 'run --silent (FIXED: previously missing from GLOBAL_LONG_OPTIONS, now recognised)' => [
            ['run', '--silent'],
            ['run', '--silent'],
        ];

        // `-p4`/`-p10` (a process count glued directly onto the shortcut) used to be
        // recognised via a regex (`/^p\d*$/`) this rewrite deliberately does not reproduce:
        // short-option recognition is now exact-shortcut-string-match only. Neither SPEC.md
        // nor README document this attached form (only `-p`, `-p 2` and `--parallel[=N]`
        // are), so this is treated as a disclosed narrowing rather than a regression.
        foreach (['run', 'record', 'verify'] as $command) {
            yield "$command -p4 (CHANGED: previously recognised via regex, now NOT — disclosed narrowing)" => [
                [$command, '-p4'],
                [$command, '--', '-p4'],
            ];
            yield "$command -p10 (CHANGED: previously recognised via regex, now NOT — disclosed narrowing)" => [
                [$command, '-p10'],
                [$command, '--', '-p10'],
            ];
        }
    }
}
