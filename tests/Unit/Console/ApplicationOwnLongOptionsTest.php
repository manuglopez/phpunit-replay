<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Console;

use Manuglopez\Replay\Console\Application;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards `Application::OWN_LONG_OPTIONS` — the argv pre-splitter's hand-maintained
 * command => long-option map, consulted by `isRecognised()` — against drifting from what
 * each command actually declares. An option missing from the map falls into the PHPUnit
 * passthrough bucket (`Application.php:160-163`), where it is then either silently
 * forwarded and ignored (`run`/`record`/`verify`, which declare an `IS_ARRAY|OPTIONAL`
 * `phpunit-args` argument) or makes Symfony throw "Too many arguments" (`status`,
 * `baseline-path`, `prune`, `push`, `pull`).
 *
 * It has shipped broken twice: `prune --remote/--keep-months/--squash` (added in
 * e9e428a, fixed 18 minutes later in 91e8c1d) and `verify --parallel` (7e06b07). Both
 * sides below are read from the real production objects — never a second hand-maintained
 * list — so this test cannot itself drift the way the constant did.
 */
final class ApplicationOwnLongOptionsTest extends TestCase
{
    /**
     * Options declared on a command's own definition but deliberately absent from
     * `OWN_LONG_OPTIONS`, because `Application::isRecognised()` special-cases them
     * itself — recognised only in their `--option=value` form (the same convention
     * `--log-junit` and `--keep-months` share), which keeps the splitter from having to
     * replicate Symfony's own "peek the next token as the value" behaviour for every
     * value-taking option, not just the `-p`/`--parallel` shortcut. A third such special
     * case added to `isRecognised()` without updating this table fails the test below
     * instead of silently drifting the way the constant itself already has twice.
     *
     * @var array<string, list<string>>
     */
    private const VALUE_ONLY_EXCEPTIONS = [
        'run' => ['log-junit'],     // Application.php:207, declared RunCommand.php:31
        'prune' => ['keep-months'], // Application.php:215, declared PruneCommand.php:41
    ];

    /** @return array{0: list<string>, 1: array<string, list<string>>, 2: list<string>} */
    private function constants(): array
    {
        $ref = new ReflectionClass(Application::class);

        /** @var list<string> $commandNames */
        $commandNames = $ref->getConstant('COMMAND_NAMES');
        self::assertIsArray($commandNames, 'Application::COMMAND_NAMES must exist');

        /** @var array<string, list<string>> $ownLongOptions */
        $ownLongOptions = $ref->getConstant('OWN_LONG_OPTIONS');
        self::assertIsArray($ownLongOptions, 'Application::OWN_LONG_OPTIONS must exist');

        /** @var list<string> $globalLongOptions */
        $globalLongOptions = $ref->getConstant('GLOBAL_LONG_OPTIONS');
        self::assertIsArray($globalLongOptions, 'Application::GLOBAL_LONG_OPTIONS must exist');

        return [$commandNames, $ownLongOptions, $globalLongOptions];
    }

    public function test_own_long_options_matches_what_each_command_actually_declares(): void
    {
        [$commandNames, $ownLongOptions, $globalLongOptions] = $this->constants();

        $app = new Application();

        foreach ($commandNames as $name) {
            // Defensive against Symfony merging the application's global options (--help,
            // --quiet, ...) into a command's own definition: OWN_LONG_OPTIONS deliberately
            // never lists those (Application::GLOBAL_LONG_OPTIONS covers them for every
            // command instead), so they are excluded here regardless of whether that
            // merge happens, rather than assumed either way.
            $declared = array_values(array_diff(
                array_keys($app->get($name)->getDefinition()->getOptions()),
                $globalLongOptions,
            ));

            $expected = array_values(array_unique([
                ...($ownLongOptions[$name] ?? []),
                ...(self::VALUE_ONLY_EXCEPTIONS[$name] ?? []),
            ]));

            self::assertEqualsCanonicalizing(
                $declared,
                $expected,
                sprintf(
                    'command "%s": OWN_LONG_OPTIONS (plus the documented VALUE_ONLY_EXCEPTIONS) must equal the options it actually declares — check both a missing entry and a dead one',
                    $name,
                ),
            );
        }
    }

    public function test_command_names_matches_the_commands_actually_registered(): void
    {
        [$commandNames] = $this->constants();

        $app = new Application();

        // Symfony's own auto-registered commands (Application::getDefaultCommands()):
        // never part of this package's own COMMAND_NAMES/OWN_LONG_OPTIONS bookkeeping.
        $autoRegistered = ['help', 'list', '_complete', 'completion'];

        $registered = array_values(array_diff(array_keys($app->all()), $autoRegistered));

        self::assertEqualsCanonicalizing(
            $commandNames,
            $registered,
            'Application::COMMAND_NAMES must equal the set of commands actually registered (minus Symfony\'s own auto-registered help/list/_complete/completion)',
        );
    }
}
