<?php

declare(strict_types=1);

namespace Manuglopez\Replay;

use PHPUnit\Runner\Extension\ParameterCollection;
use Throwable;

/**
 * Package configuration (SPEC §9 keys). Built from, in increasing priority: hard defaults,
 * the project's `phpunit-replay.php` (an array-returning file, snake_case keys), the PHPUnit
 * extension `<parameter>` block (camelCase names, SPEC §3.2), and environment variables
 * (`PHPUNIT_REPLAY_*`), which always win last via {@see self::mergeEnv()}.
 *
 * `phpunit-replay.php` is `require`d for its return value only: this class defines no global
 * `env()` helper, so a config file that needs environment values should call `getenv()`
 * directly (Laravel apps already have `env()` from the framework itself).
 *
 * Never throws: a missing/unreadable/malformed config file, or a value of the wrong type
 * anywhere, falls back to the corresponding default (docs/INTERNALS.md).
 */
final readonly class Config
{
    /** @var list<string> */
    private const KNOWN_MODES = ['auto', 'record', 'replay', 'off', 'record-subset', 'results-only'];

    /** @var list<string> */
    private const LARAVEL_MODES = ['auto', 'on', 'off'];

    /**
     * @param array<string, string|list<string>> $watch
     * @param list<string> $neverCache
     */
    public function __construct(
        public ?string $stateDir,
        public ?string $remote,
        public ?string $remoteToken,
        public ?string $defaultBranch,
        public array $watch,
        public array $neverCache,
        public int $quarantineReleaseAfter,
        public string $laravel,
        public bool $junitMerge,
        public string $mode,
        public bool $hermeticityHeuristics,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            stateDir: null,
            remote: null,
            remoteToken: null,
            defaultBranch: null,
            watch: [],
            neverCache: [],
            quarantineReleaseAfter: 20,
            laravel: 'auto',
            junitMerge: true,
            mode: 'auto',
            hermeticityHeuristics: false,
        );
    }

    /**
     * Builds a Config from the array a `phpunit-replay.php` file returns. Unknown keys are
     * ignored; a present key whose value has the wrong type falls back to the default for
     * that key. Never throws.
     *
     * @param array<mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = self::defaults();

        return new self(
            stateDir: self::stringOrDefault($values['state_dir'] ?? null, $defaults->stateDir),
            remote: self::stringOrDefault($values['remote'] ?? null, $defaults->remote),
            remoteToken: self::stringOrDefault($values['remote_token'] ?? null, $defaults->remoteToken),
            defaultBranch: self::stringOrDefault($values['default_branch'] ?? null, $defaults->defaultBranch),
            watch: self::watchOrDefault($values['watch'] ?? null),
            neverCache: self::stringListOrDefault($values['never_cache'] ?? null),
            quarantineReleaseAfter: self::intOrDefault($values['quarantine_release_after'] ?? null, $defaults->quarantineReleaseAfter),
            laravel: self::enumOrDefault($values['laravel'] ?? null, self::LARAVEL_MODES, $defaults->laravel),
            junitMerge: self::boolOrDefault($values['junit_merge'] ?? null, $defaults->junitMerge),
            mode: self::modeOrDefault($values['mode'] ?? null, $defaults->mode),
            hermeticityHeuristics: self::boolOrDefault($values['hermeticity_heuristics'] ?? null, $defaults->hermeticityHeuristics),
        );
    }

    /**
     * Loads `<projectRoot>/phpunit-replay.php` when present (must return an array; anything
     * else — missing file, non-array return, a thrown error — falls back to defaults()), then
     * applies environment overrides ({@see self::mergeEnv()}).
     */
    public static function load(string $projectRoot): self
    {
        $file = rtrim($projectRoot, '/') . '/phpunit-replay.php';
        $values = self::requireConfigArray($file);
        $base = is_array($values) ? self::fromArray($values) : self::defaults();

        return $base->mergeEnv($_SERVER);
    }

    /**
     * Reads the extension `<parameter>` block (SPEC §3.2): `mode`, `stateDir`, `remote`,
     * `remoteToken`, `defaultBranch`. An absent parameter, or one whose value is the empty
     * string, is treated as unset (falls back to defaults). The other Config keys have no
     * extension parameter and stay at their defaults.
     */
    public static function fromExtensionParameters(ParameterCollection $parameters): self
    {
        $defaults = self::defaults();

        return new self(
            stateDir: self::parameterOrDefault($parameters, 'stateDir', $defaults->stateDir),
            remote: self::parameterOrDefault($parameters, 'remote', $defaults->remote),
            remoteToken: self::parameterOrDefault($parameters, 'remoteToken', $defaults->remoteToken),
            defaultBranch: self::parameterOrDefault($parameters, 'defaultBranch', $defaults->defaultBranch),
            watch: $defaults->watch,
            neverCache: $defaults->neverCache,
            quarantineReleaseAfter: $defaults->quarantineReleaseAfter,
            laravel: $defaults->laravel,
            junitMerge: $defaults->junitMerge,
            mode: self::parameterModeOrDefault($parameters, $defaults->mode),
            hermeticityHeuristics: $defaults->hermeticityHeuristics,
        );
    }

    /**
     * Applies environment overrides on top of this instance: `PHPUNIT_REPLAY_STATE_DIR`,
     * `PHPUNIT_REPLAY_REMOTE`, `PHPUNIT_REPLAY_REMOTE_TOKEN`, `PHPUNIT_REPLAY_DEFAULT_BRANCH`
     * always win when set to a non-empty string; `PHPUNIT_REPLAY_MODE` wins only when it is a
     * value the extension mode enum accepts ({@see self::isKnownMode()}) — the wrapper also
     * uses the internal `record-subset` / `results-only` values here, not just the four modes
     * documented for the extension `<parameter>`. Anything absent, empty, or invalid keeps the
     * current value.
     *
     * @param array<array-key, mixed> $server
     */
    public function mergeEnv(array $server): self
    {
        return $this->with([
            'stateDir' => self::envStringOrDefault($server, 'PHPUNIT_REPLAY_STATE_DIR', $this->stateDir),
            'remote' => self::envStringOrDefault($server, 'PHPUNIT_REPLAY_REMOTE', $this->remote),
            'remoteToken' => self::envStringOrDefault($server, 'PHPUNIT_REPLAY_REMOTE_TOKEN', $this->remoteToken),
            'defaultBranch' => self::envStringOrDefault($server, 'PHPUNIT_REPLAY_DEFAULT_BRANCH', $this->defaultBranch),
            'mode' => self::envModeOrDefault($server, $this->mode),
        ]);
    }

    /** Any value the extension mode enum accepts: `auto|record|replay|off|record-subset|results-only`. */
    public static function isKnownMode(string $mode): bool
    {
        return in_array($mode, self::KNOWN_MODES, true);
    }

    /**
     * Returns a copy with the given properties overridden.
     *
     * @param array{
     *     stateDir?: ?string,
     *     remote?: ?string,
     *     remoteToken?: ?string,
     *     defaultBranch?: ?string,
     *     watch?: array<string, string|list<string>>,
     *     neverCache?: list<string>,
     *     quarantineReleaseAfter?: int,
     *     laravel?: string,
     *     junitMerge?: bool,
     *     mode?: string,
     *     hermeticityHeuristics?: bool,
     * } $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            stateDir: array_key_exists('stateDir', $overrides) ? $overrides['stateDir'] : $this->stateDir,
            remote: array_key_exists('remote', $overrides) ? $overrides['remote'] : $this->remote,
            remoteToken: array_key_exists('remoteToken', $overrides) ? $overrides['remoteToken'] : $this->remoteToken,
            defaultBranch: array_key_exists('defaultBranch', $overrides) ? $overrides['defaultBranch'] : $this->defaultBranch,
            watch: array_key_exists('watch', $overrides) ? $overrides['watch'] : $this->watch,
            neverCache: array_key_exists('neverCache', $overrides) ? $overrides['neverCache'] : $this->neverCache,
            quarantineReleaseAfter: array_key_exists('quarantineReleaseAfter', $overrides) ? $overrides['quarantineReleaseAfter'] : $this->quarantineReleaseAfter,
            laravel: array_key_exists('laravel', $overrides) ? $overrides['laravel'] : $this->laravel,
            junitMerge: array_key_exists('junitMerge', $overrides) ? $overrides['junitMerge'] : $this->junitMerge,
            mode: array_key_exists('mode', $overrides) ? $overrides['mode'] : $this->mode,
            hermeticityHeuristics: array_key_exists('hermeticityHeuristics', $overrides) ? $overrides['hermeticityHeuristics'] : $this->hermeticityHeuristics,
        );
    }

    /** `require`s `$file` inside a closure (isolated scope) and returns whatever it returns; null on any failure. */
    private static function requireConfigArray(string $file): mixed
    {
        if (! is_file($file)) {
            return null;
        }

        $loader = static function (string $path): mixed {
            return require $path;
        };

        try {
            return $loader($file);
        } catch (Throwable) {
            return null;
        }
    }

    private static function parameterOrDefault(ParameterCollection $parameters, string $name, ?string $default): ?string
    {
        if (! $parameters->has($name)) {
            return $default;
        }

        $value = $parameters->get($name);

        return $value === '' ? $default : $value;
    }

    private static function parameterModeOrDefault(ParameterCollection $parameters, string $default): string
    {
        if (! $parameters->has('mode')) {
            return $default;
        }

        $value = $parameters->get('mode');

        if ($value === '' || ! self::isKnownMode($value)) {
            return $default;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $server */
    private static function envStringOrDefault(array $server, string $key, ?string $default): ?string
    {
        $value = $server[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    /** @param array<array-key, mixed> $server */
    private static function envModeOrDefault(array $server, string $default): string
    {
        $value = $server['PHPUNIT_REPLAY_MODE'] ?? null;

        if (! is_string($value) || $value === '' || ! self::isKnownMode($value)) {
            return $default;
        }

        return $value;
    }

    private static function stringOrDefault(mixed $value, ?string $default): ?string
    {
        return is_string($value) ? $value : $default;
    }

    private static function boolOrDefault(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    private static function intOrDefault(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }

    /** @param list<string> $allowed */
    private static function enumOrDefault(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private static function modeOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && self::isKnownMode($value) ? $value : $default;
    }

    /** @return list<string> */
    private static function stringListOrDefault(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $entry) {
            if (is_string($entry)) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param array<mixed> $value
     *
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isListOfStrings(array $value): bool
    {
        if (! array_is_list($value)) {
            return false;
        }

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string|list<string>> */
    private static function watchOrDefault(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                continue;
            }

            if (is_string($entry)) {
                $result[$key] = $entry;

                continue;
            }

            if (is_array($entry) && self::isListOfStrings($entry)) {
                $result[$key] = $entry;
            }
        }

        return $result;
    }
}
