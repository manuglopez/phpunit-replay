<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Analysis;

/**
 * What the static classifier ({@see DeclarationScanner}) knows about one PHP source file.
 *
 * Two questions matter, and both are about *when* PHP executes a line:
 *
 *  - `$bodies` — the inclusive line ranges of every function/method/closure statement list.
 *    A line inside one of those ranges only ever runs when something *calls* into it, so a
 *    coverage hit there is order-independent: every caller produces it, every time.
 *  - {@see self::declarationOnly()} — a file with no such range at all. PHP executes a
 *    file's top level exactly once per process, so the *only* coverage such a file can ever
 *    produce is credited to whichever test in that process loaded it first
 *    ({@see \Manuglopez\Replay\Cache\Graph::unionEdges()}). That signal is not weak, it is
 *    meaningless, and it is what {@see StaticEdges} replaces with a name-resolution edge.
 *
 * `$parsed === false` means nikic/php-parser could not read the file at all (unreadable, or
 * syntax PHP itself would reject). Nothing is known about it, so callers must fall back to
 * their pre-existing behaviour rather than treat it as "no bodies" — an unparseable file
 * classified as declaration-only would silently lose every edge it has.
 *
 * Arrow functions deliberately contribute no range: `fn () => $x` has an expression body,
 * php-parser reports it as a synthetic statement with no line number, and the expression
 * shares its line with the enclosing top-level statement. Counting it would make a
 * `return ['handler' => fn () => ...]` config file look behavioural on the very line its
 * load-time execution covers.
 *
 * This describes when PHP runs a line, never what a coverage driver happens to report about
 * it, and the difference is not hypothetical: pcov 1.0.12 instruments no PHP 8.4 property
 * hook at all, in either direction, while xdebug 3.5.3 reports a hook's line on access and
 * not at load. A hook body is call-time code, so it is a `$bodies` range in both cases
 * ({@see FactsVisitor::recordBody()}); what a hooks-only class then gets under pcov is no
 * behavioural edge and a static edge from the tests whose own source names it
 * ({@see StaticEdges::collect()}), the same as any other file whose bodies a test never
 * enters. A driver-dependent classifier would be far worse than that: the driver is an
 * *environmental* fingerprint key ({@see \Manuglopez\Replay\Cache\Fingerprint}), so one
 * graph accumulates edges recorded under either, and two machines would disagree about what
 * a file *is*.
 */
final readonly class FileFacts
{
    /**
     * @param list<array{int, int}> $bodies inclusive `[firstLine, lastLine]` ranges, sorted
     * @param list<string> $declares fully-qualified class-like names declared here, sorted
     * @param list<string> $references fully-qualified names mentioned here, sorted
     */
    public function __construct(
        public bool $parsed,
        public array $bodies,
        public array $declares,
        public array $references,
    ) {
    }

    /** php-parser could not read this file: nothing is known, and nothing may be inferred. */
    public static function unparseable(): self
    {
        return new self(false, [], [], []);
    }

    /**
     * True when the file has no function/method/closure body at all: an enum with only
     * cases, an interface, a constants-only class, a `return [...]` config or language
     * file. Always false for an unparseable file.
     */
    public function declarationOnly(): bool
    {
        return $this->parsed && $this->bodies === [];
    }

    /**
     * Whether any of `$lines` falls inside a function/method/closure body — the
     * behavioural-edge test. `false` for a file whose coverage is entirely load-time
     * (class/const/property declarations, a top-level `return [...]`).
     *
     * @param list<int> $lines executed line numbers
     */
    public function coversAnyBodyLine(array $lines): bool
    {
        foreach ($lines as $line) {
            foreach ($this->bodies as [$from, $to]) {
                if ($line >= $from && $line <= $to) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{p: bool, b: list<array{int, int}>, d: list<string>, r: list<string>} */
    public function toArray(): array
    {
        return ['p' => $this->parsed, 'b' => $this->bodies, 'd' => $this->declares, 'r' => $this->references];
    }

    /** null when `$raw` is not a payload {@see self::toArray()} produced. */
    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw) || ! is_bool($raw['p'] ?? null)) {
            return null;
        }

        return new self(
            $raw['p'],
            self::decodeRanges($raw['b'] ?? null),
            self::decodeNames($raw['d'] ?? null),
            self::decodeNames($raw['r'] ?? null),
        );
    }

    /** @return list<array{int, int}> */
    private static function decodeRanges(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $range) {
            if (! is_array($range) || ! is_int($range[0] ?? null) || ! is_int($range[1] ?? null)) {
                continue;
            }

            $out[] = [$range[0], $range[1]];
        }

        return $out;
    }

    /** @return list<string> */
    private static function decodeNames(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $name) {
            if (is_string($name) && $name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }
}
