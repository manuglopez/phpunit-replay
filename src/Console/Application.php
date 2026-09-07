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
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
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
 */
final class Application extends BaseApplication
{
    /** @var list<string> */
    private const COMMAND_NAMES = ['run', 'record', 'status', 'baseline-path', 'explain', 'prune', 'verify', 'push', 'pull'];

    /** @var array<string, list<string>> command => its own recognised long options (without leading --) */
    private const OWN_LONG_OPTIONS = [
        'run' => ['fresh', 'no-remote', 'explain', 'dry-run', 'allow-ci-baseline', 'in-process', 'filtered', 'parallel'],
        'record' => ['fresh', 'parallel'],
        'status' => [],
        'baseline-path' => [],
        'explain' => [],
        'prune' => ['flaky', 'branches', 'all', 'remote', 'squash'],
        'verify' => [],
        'push' => ['graph'],
        'pull' => [],
    ];

    /** @var list<string> long options every command recognises (Symfony's own global definition) */
    private const GLOBAL_LONG_OPTIONS = ['help', 'version', 'quiet', 'verbose', 'ansi', 'no-ansi', 'no-interaction'];

    /** @var list<string> single-character short options every command recognises */
    private const GLOBAL_SHORT_OPTIONS = ['h', 'V', 'q', 'n'];

    /** @var list<string> commands whose own `-p[N]` shortcut is `--parallel` (SPEC §13) */
    private const PARALLEL_SHORTCUT_COMMANDS = ['run', 'record'];

    public function __construct()
    {
        parent::__construct('phpunit-replay', Version::ID);

        $this->addCommand(new RunCommand());
        $this->addCommand(new RecordCommand());
        $this->addCommand(new StatusCommand());
        $this->addCommand(new BaselinePathCommand());
        $this->addCommand(new ExplainCommand());
        $this->addCommand(new PruneCommand());
        $this->addCommand(new VerifyCommand());
        $this->addCommand(new PushCommand());
        $this->addCommand(new PullCommand());

        $this->setDefaultCommand('run');
    }

    public function run(?InputInterface $input = null, ?OutputInterface $output = null): int
    {
        if ($input === null) {
            $argv = self::rawArgv();
            array_shift($argv);
            $input = new ArgvInput(['phpunit-replay', ...self::splitPassthrough($argv)]);
        }

        return parent::run($input, $output);
    }

    /**
     * @param list<string> $argv raw CLI tokens, without the script name
     * @return list<string>
     */
    private static function splitPassthrough(array $argv): array
    {
        // `list`/`help` are Symfony's own built-in commands; let them parse normally.
        if (isset($argv[0]) && ($argv[0] === 'list' || $argv[0] === 'help')) {
            return $argv;
        }

        $command = 'run';
        $rest = $argv;

        if (isset($rest[0]) && in_array($rest[0], self::COMMAND_NAMES, true)) {
            $command = array_shift($rest);
        }

        $own = [];
        $passthrough = [];
        $inPassthrough = false;
        $explainPathSeen = false;
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

            // `explain` takes exactly one positional argument (the path itself), which
            // must stay "own" rather than fall into the phpunit passthrough bucket below.
            if ($command === 'explain' && ! $explainPathSeen && $token !== '' && $token[0] !== '-') {
                $own[] = $token;
                $explainPathSeen = true;

                continue;
            }

            if (self::isRecognised($command, $token)) {
                $own[] = $token;

                // `-p 2` / `--parallel 2`: Symfony's own ArgvInput, once it sees these two
                // tokens back to back, peeks the second and consumes it as the option's value
                // the same way (`addLongOption()`) — this only has to keep the pair together
                // rather than letting the bare option go to $own and "2" fall through to
                // $passthrough as an unrelated phpunit argument.
                if (self::isBareParallelOption($command, $token) && isset($rest[$i + 1]) && self::looksLikeAnOptionValue($rest[$i + 1])) {
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

    private static function isRecognised(string $command, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        if (str_starts_with($token, '--')) {
            $name = substr($token, 2);
            $equals = strpos($name, '=');
            $bareName = $equals === false ? $name : substr($name, 0, $equals);

            if ($command === 'run' && $bareName === 'log-junit' && $equals !== false) {
                return true;
            }

            // `prune --keep-months` (InputOption::VALUE_REQUIRED) is only recognised in its
            // `=value` form, the same convention as `--log-junit` above: it keeps this splitter
            // from having to replicate Symfony's "peek the next token as the value" behaviour
            // for every value-taking option, not just the `-p`/`--parallel` shortcut.
            if ($command === 'prune' && $bareName === 'keep-months' && $equals !== false) {
                return true;
            }

            return in_array($bareName, self::OWN_LONG_OPTIONS[$command] ?? [], true)
                || in_array($bareName, self::GLOBAL_LONG_OPTIONS, true);
        }

        if ($token[0] === '-' && $token !== '-') {
            $name = substr($token, 1);

            if (preg_match('/^v+$/', $name) === 1) {
                return true;
            }

            if (in_array($command, self::PARALLEL_SHORTCUT_COMMANDS, true) && preg_match('/^p\d*$/', $name) === 1) {
                return true;
            }

            return strlen($name) === 1 && in_array($name, self::GLOBAL_SHORT_OPTIONS, true);
        }

        return false;
    }

    /** `-p` or `--parallel` with no attached value — the two forms Symfony peeks a following token for. */
    private static function isBareParallelOption(string $command, string $token): bool
    {
        if (! in_array($command, self::PARALLEL_SHORTCUT_COMMANDS, true)) {
            return false;
        }

        return $token === '-p' || $token === '--parallel';
    }

    /** Mirrors `ArgvInput::addLongOption()`'s own peek: anything that doesn't itself look like an option. */
    private static function looksLikeAnOptionValue(string $token): bool
    {
        return $token === '' || $token[0] !== '-';
    }
}
