<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Console;

use Manuglopez\Replay\Console\Commands\BaselinePathCommand;
use Manuglopez\Replay\Console\Commands\ExplainCommand;
use Manuglopez\Replay\Console\Commands\PruneCommand;
use Manuglopez\Replay\Console\Commands\PullCommand;
use Manuglopez\Replay\Console\Commands\PushCommand;
use Manuglopez\Replay\Console\Commands\RecordCommand;
use Manuglopez\Replay\Console\Commands\RunCommand;
use Manuglopez\Replay\Console\Commands\StatusCommand;
use Manuglopez\Replay\Console\Commands\VerifyCommand;
use Manuglopez\Replay\Version;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The `phpunit-replay` CLI (SPEC.md §11). `run` is the default command, so both
 * `phpunit-replay -- --testdox` and bare `phpunit-replay --testdox` work: everything
 * after a literal `--`, or from the first option/argument this application does not
 * recognise, is forwarded to `vendor/bin/phpunit` untouched.
 *
 * Symfony's `ArgvInput` already treats a literal `--` as "stop parsing options" (it does
 * so natively, see `ArgvInput::parseToken()`), which is enough for the first case. The
 * second case — an unrecognised option with no preceding `--` — is not something Symfony
 * supports out of the box: an unregistered long option makes `ArgvInput::bind()` throw
 * before any command-specific argument parsing happens at all, `ignoreValidationErrors()`
 * would only swallow that (`Command::run()`, `vendor/symfony/console/Command/Command.php`)
 * without recovering the tokens that triggered it, and `setDefaultCommand()` only decides
 * *which* command runs when none is named, it does not change how its arguments are parsed.
 * So the raw argv is pre-split by hand, once, before Symfony ever sees it: the command name
 * is resolved first, then every following token is classified as either "recognised by this
 * command" (an actual phpunit-replay option) or "everything from here on is for PHPUnit" —
 * the first unrecognised token, like an explicit `--`, switches into passthrough for the
 * remainder. The `run`/`record` commands then declare `phpunit-args` as an
 * `InputArgument::IS_ARRAY` argument, which is what receives that inserted `--` and
 * everything after it.
 *
 * `splitPassthrough()` decides ownership entirely from the live Symfony objects — each
 * command's own `getNativeDefinition()` and the application's own `getDefinition()` —
 * never from a hand-maintained list of option/shortcut names. Both are safe to read at
 * this point in the lifecycle even though `parent::run()` has not been called yet: a
 * command's `getDefinition()` only returns the merged (command + application) definition
 * once `mergeApplicationDefinition()` has run, which happens inside `Command::run()` and
 * `Application::doRunCommand()` — both downstream of here — so `getNativeDefinition()` is
 * used deliberately instead: it returns `$this->definition`, populated by `configure()`
 * inside the command's own constructor and therefore already complete the moment
 * `addCommand()` built it, regardless of merge timing — exactly the command-only,
 * global-free option set this splitter needs. The application's own `getDefinition()`
 * (global options and shortcuts: `--help`/`-h`, `--quiet`/`-q`, `--verbose`/`-v|-vv|-vvv`,
 * ...) is lazily built the first time anything asks for it and cached from then on, also
 * unaffected by anything downstream. This is what makes correctness here independent of
 * which Symfony version is installed (`composer.json` admits `^6.4 || ^7.0 || ^8.0`):
 * Symfony 8.1 added a `--silent` global option that the hand-maintained list this replaced
 * had no way to know about (confirmed missing from it — see `git log` on this file) —
 * reading the live definition means a newly-added global option is automatically
 * recognised on every supported Symfony version without this class ever being touched
 * again.
 *
 * `-p`/`--parallel`'s "peek the next token as its value" behaviour (`acceptsPeekedValue()`)
 * and the two `--option=value`-only forms `--log-junit`/`--keep-months` both fall out of
 * one rule — `InputOption::isValueRequired()`/`isValueOptional()` — rather than three
 * separate hardcoded cases: `--log-junit`/`--keep-months` are the only `VALUE_REQUIRED`
 * options anywhere in this CLI, `--parallel` is the only `VALUE_OPTIONAL` one, and nothing
 * else accepts a value at all.
 *
 * Shortcut recognition (`-h`, `-p`, `-v`/`-vv`/`-vvv`, `-p4`, `-p=4`, ...) is
 * `resolveShortToken()`'s two-level rule, entirely from `hasShortcut()`/`acceptValue()`,
 * naming no option: the WHOLE remainder as one exact shortcut key first (which is also
 * where Symfony itself expands `--verbose`'s `-v|-vv|-vvv` alias string into three
 * independent keys, so no regex is needed there), and only if that fails, the first
 * character alone as the shortcut with anything left over as its glued value — owned
 * whenever that shortcut `acceptValue()`s (`-p4`, `-p10`, `-p=4` all resolve to
 * `--parallel` this way), an unimplemented cluster otherwise (`-hq`: not recognised,
 * because `-p` is the only own shortcut anywhere in this CLI, so there is nothing to
 * cluster it *with*). `emit()` additionally strips a literal leading `=` from a short
 * option's glued value (`-p=4` → `-p4`) before handing the token on, because Symfony's own
 * `ArgvInput` does not do that itself — confirmed empirically against the installed
 * `symfony/console`, not assumed — even though SPEC.md/README document the form as
 * `-p[=N]`.
 *
 * `tests/Unit/Console/ApplicationArgvSplitTest.php` is the behavioural contract for all of
 * this: a corpus generated by running the previous (hand-maintained-constants)
 * implementation and read back by hand, which this implementation satisfies unchanged
 * except for the handful of cases listed in that file's own docblock, where the previous
 * implementation's answer was itself a bug.
 */
final class Application extends BaseApplication
{
    /** Names of the commands this application itself registers, captured as it registers
     *  them (never a separately hand-maintained list — see the constructor) — Symfony's
     *  own auto-registered `help`/`list`/`_complete`/`completion` are deliberately not
     *  among them, matching this splitter's pre-existing behaviour of treating any of
     *  those four as an ordinary (unrecognised) token when typed as the first argument.
     *
     * @var list<string>
     */
    private array $ownCommandNames = [];

    public function __construct()
    {
        parent::__construct('phpunit-replay', Version::ID);

        foreach (self::ownCommands() as $command) {
            $this->addCommand($command);

            $name = $command->getName();

            if ($name !== null) {
                $this->ownCommandNames[] = $name;
            }
        }

        $this->setDefaultCommand('run');
    }

    /** @return list<Command> */
    private static function ownCommands(): array
    {
        return [
            new RunCommand(),
            new RecordCommand(),
            new StatusCommand(),
            new BaselinePathCommand(),
            new ExplainCommand(),
            new PruneCommand(),
            new VerifyCommand(),
            new PushCommand(),
            new PullCommand(),
        ];
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        if ($input === null) {
            $argv = self::rawArgv();
            array_shift($argv);
            $input = new ArgvInput(['phpunit-replay', ...$this->splitPassthrough($argv)]);
        }

        return parent::run($input, $output);
    }

    /**
     * @param list<string> $argv raw CLI tokens, without the script name
     * @return list<string>
     */
    private function splitPassthrough(array $argv): array
    {
        // `list`/`help` are Symfony's own built-in commands; let them parse normally.
        if (isset($argv[0]) && ($argv[0] === 'list' || $argv[0] === 'help')) {
            return $argv;
        }

        $command = 'run';
        $rest = $argv;

        if (isset($rest[0]) && in_array($rest[0], $this->ownCommandNames, true)) {
            $command = array_shift($rest);
        }

        $ownDefinition = $this->get($command)->getNativeDefinition();
        $globalDefinition = $this->getDefinition();

        // A command's own non-array positional argument(s) — currently only `explain
        // <path>` — greedily claim the first non-option-looking token(s), the same way
        // Symfony's own ArgvInput::parseArgument() would once it actually got to parse
        // them, without hardcoding which command that is: `run`/`record`/`verify`'s own
        // `phpunit-args` is IS_ARRAY, so it never counts here, and everything else that
        // isn't `explain` declares no argument at all.
        $positionalSlotsRemaining = count(array_filter(
            $ownDefinition->getArguments(),
            static fn (InputArgument $argument): bool => ! $argument->isArray(),
        ));

        $own = [];
        $passthrough = [];
        $inPassthrough = false;
        $count = count($rest);

        for ($i = 0; $i < $count; $i++) {
            $token = $rest[$i];

            if ($inPassthrough) {
                $passthrough[] = $token;

                continue;
            }

            if ($token === '--') {
                $inPassthrough = true;

                continue;
            }

            if ($positionalSlotsRemaining > 0 && $token !== '' && $token[0] !== '-') {
                $own[] = $token;
                $positionalSlotsRemaining--;

                continue;
            }

            if (self::isRecognised($ownDefinition, $globalDefinition, $token)) {
                $own[] = self::emit($ownDefinition, $globalDefinition, $token);

                // `-p 2` / `--parallel 2`: Symfony's own ArgvInput, once it sees these two
                // tokens back to back, peeks the second and consumes it as the option's
                // value the same way (`addLongOption()`) — this only has to keep the pair
                // together rather than letting the bare option go to $own and "2" fall
                // through to $passthrough as an unrelated phpunit argument.
                if (self::acceptsPeekedValue($ownDefinition, $globalDefinition, $token) && isset($rest[$i + 1]) && self::looksLikeAnOptionValue($rest[$i + 1])) {
                    $own[] = $rest[++$i];
                }

                continue;
            }

            $inPassthrough = true;
            $passthrough[] = $token;
        }

        $result = [$command, ...$own];

        if ($passthrough !== []) {
            $result[] = '--';
            array_push($result, ...$passthrough);
        }

        return $result;
    }

    /** @return list<string> */
    private static function rawArgv(): array
    {
        $argv = $_SERVER['argv'] ?? [];

        if (! is_array($argv)) {
            return [];
        }

        $strings = [];

        foreach ($argv as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * Recognised whenever the bare option name (or, for a negatable option, its `no-`
     * form — e.g. `--no-ansi`) matches something the command or the application itself
     * declares. A `VALUE_REQUIRED` option additionally requires the value to already be
     * attached via `=`: this splitter only ever peeks a following token for a
     * `VALUE_OPTIONAL` option (see `acceptsPeekedValue()`), which is what subsumes the
     * previous hand-written `--log-junit=`/`--keep-months=` special cases without naming
     * either option — `isValueRequired()` is true for exactly those two and nothing else
     * in this CLI. A short option (`-p4`, `-p=4`, `-vv`, ...) is recognised by
     * `resolveShortToken()`, below.
     */
    private static function isRecognised(InputDefinition $own, InputDefinition $global, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        if (str_starts_with($token, '--')) {
            [$bareName, $hasValue] = self::splitLongOptionToken($token);
            $option = self::resolveOption($own, $global, $bareName);

            if ($option !== null) {
                return $option->isValueRequired() ? $hasValue : true;
            }

            return $own->hasNegation($bareName) || $global->hasNegation($bareName);
        }

        if ($token[0] === '-' && $token !== '-') {
            return self::resolveShortToken($own, $global, substr($token, 1)) !== null;
        }

        return false;
    }

    /**
     * `-p` / `--parallel` with no value already attached — the one case this splitter
     * peeks a following token for, because `--parallel` is the only `VALUE_OPTIONAL`
     * option anywhere in this CLI (subsumes the previous literal-string
     * `isBareParallelOption()`, without naming `parallel` or `p`). A short option that
     * already has a value glued onto it (`-p4`, `-p=4`) never reaches this: there is
     * nothing left to peek.
     */
    private static function acceptsPeekedValue(InputDefinition $own, InputDefinition $global, string $token): bool
    {
        if (str_starts_with($token, '--')) {
            [$bareName, $hasValue] = self::splitLongOptionToken($token);

            if ($hasValue) {
                return false;
            }

            $option = self::resolveOption($own, $global, $bareName);

            return $option !== null && $option->isValueOptional();
        }

        $resolved = self::resolveShortToken($own, $global, substr($token, 1));

        return $resolved !== null && $resolved[1] === '' && $resolved[0]->isValueOptional();
    }

    /**
     * Resolves the part of a short-option token after its leading `-` (e.g. `p`, `p4`,
     * `p=4`, `vv`, `hq`) against Symfony's own two-level shortcut rule, entirely from
     * `hasShortcut()`/`acceptValue()` — no option name appears anywhere in it:
     *
     * 1. First, the WHOLE remainder as one exact shortcut key. This is what makes
     *    `--verbose`'s `-v|-vv|-vvv` alias string resolve without a regex: Symfony
     *    itself explodes that string into three independent keys (`v`, `vv`, `vvv`),
     *    not a pattern, so `hasShortcut('vv')` is already a real, exact match.
     * 2. Only if that fails, the first character alone becomes the shortcut
     *    (`ArgvInput::parseShortOption()`'s own rule). Anything left over is that
     *    option's glued value if it `acceptValue()`s (the token is self-contained: `-p4`,
     *    `-p10`, `-p=4` all resolve to the `parallel` option this way) — or, if it does
     *    not, an unimplemented cluster (`-hq`): not recognised. Clustering genuinely
     *    isn't implemented, because nothing in this CLI needs it (`-p` is the only own
     *    shortcut anywhere, so there is nothing to cluster it *with*).
     *
     * @return array{0: InputOption, 1: string}|null [the resolved option, its glued
     *     value — `''` when step 1 matched, since an exact multi-character shortcut key
     *     has nothing left over by definition] or null if not recognised at all.
     */
    private static function resolveShortToken(InputDefinition $own, InputDefinition $global, string $remainder): ?array
    {
        $whole = self::resolveShortcut($own, $global, $remainder);

        if ($whole !== null) {
            return [$whole, ''];
        }

        $option = self::resolveShortcut($own, $global, $remainder[0]);

        if ($option === null) {
            return null;
        }

        $glued = substr($remainder, 1);

        if ($glued === '') {
            return [$option, ''];
        }

        return $option->acceptValue() ? [$option, $glued] : null;
    }

    private static function resolveOption(InputDefinition $own, InputDefinition $global, string $name): ?InputOption
    {
        if ($own->hasOption($name)) {
            return $own->getOption($name);
        }

        return $global->hasOption($name) ? $global->getOption($name) : null;
    }

    private static function resolveShortcut(InputDefinition $own, InputDefinition $global, string $shortcut): ?InputOption
    {
        if ($own->hasShortcut($shortcut)) {
            return $own->getOptionForShortcut($shortcut);
        }

        return $global->hasShortcut($shortcut) ? $global->getOptionForShortcut($shortcut) : null;
    }

    /** @return array{0: string, 1: bool} [the bare option name, whether `=value` is attached] */
    private static function splitLongOptionToken(string $token): array
    {
        $name = substr($token, 2);
        $equals = strpos($name, '=');

        return [$equals === false ? $name : substr($name, 0, $equals), $equals !== false];
    }

    /**
     * The token actually pushed into `$own` for a recognised token — identical to the
     * original except for a short option's glued value carrying a literal leading `=`
     * (`-p=4`): SPEC.md and README document `--parallel`/`-p` as `-p[=N]`, but Symfony's
     * own `ArgvInput` does not strip that `=` itself (empirically: `-p=4` parses to the
     * literal option value `"=4"`, not `"4"` — confirmed against the installed
     * `symfony/console`, not assumed), so the documented short form would silently
     * request Paratest's auto-detected process count instead of the one actually typed.
     * This is the one place that convention can be honoured: strip exactly one leading
     * `=` from the glued value before handing the token on, the same way `=` already
     * works for the long form (`--parallel=4`), which Symfony parses correctly natively.
     */
    private static function emit(InputDefinition $own, InputDefinition $global, string $token): string
    {
        if (str_starts_with($token, '--') || $token === '-' || $token === '') {
            return $token;
        }

        $remainder = substr($token, 1);
        $resolved = self::resolveShortToken($own, $global, $remainder);

        if ($resolved === null || $resolved[1] === '' || $resolved[1][0] !== '=') {
            return $token;
        }

        return '-' . $remainder[0] . substr($resolved[1], 1);
    }

    /** Mirrors `ArgvInput::addLongOption()`'s own peek: anything that doesn't itself look like an option. */
    private static function looksLikeAnOptionValue(string $token): bool
    {
        return $token === '' || $token[0] !== '-';
    }
}
