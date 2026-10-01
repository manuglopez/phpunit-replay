<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

/**
 * The result of Selector::affected(): which test files are affected and why. A test file
 * may accumulate several Reason objects (one per rule/trigger that pointed at it); insertion
 * order is preserved and identical reasons are not duplicated.
 */
final class Selection
{
    /**
     * The rules whose trigger a content key `k` already contains (SPEC.md §9,
     * `Cache\ContentKey`): `k` hashes the test file itself and every file it executed, so a
     * change to either is exactly what `PhpEdge` and `TestFile` report, and an object stored
     * under the new `k` is proof about that very change. Any other rule (`Watch`, which also
     * carries the residue fallback, `Migration`, `Blade`, `Sibling`, whatever is added next)
     * selects for something `k` does not see: the trigger is not among the file's recorded
     * dependencies, so `k` is unchanged and an object stored under it says nothing about it.
     * A whitelist rather than a list of the rules known to be unsafe, so a new rule is safe
     * by default and has to be added here deliberately.
     */
    private const KEY_COVERED_RULES = ['PhpEdge', 'TestFile'];

    /** @var array<string, list<Reason>> */
    private array $reasons = [];

    public function __construct(public readonly bool $sourcePhpChanged = false)
    {
    }

    public function add(string $testFile, Reason $reason): void
    {
        foreach ($this->reasons[$testFile] ?? [] as $existing) {
            if (
                $existing->rule === $reason->rule
                && $existing->trigger === $reason->trigger
                && $existing->detail === $reason->detail
            ) {
                return;
            }
        }

        $this->reasons[$testFile][] = $reason;
    }

    /**
     * Whether a remote object addressed by the content key may stand in for running this
     * file: it is selected, and EVERY reason it was selected is one `k` covers. One
     * uncovered reason is enough to make it execute, because the object cannot tell whether
     * that trigger broke the test. The rule for an object without a non-edge digest; one with
     * a digest answers for every trigger itself (`Cache\Remote\ObjectStore::resultsFor()`).
     */
    public function coveredByContentKey(string $testFile): bool
    {
        $reasons = $this->reasons[$testFile] ?? [];

        if ($reasons === []) {
            return false;
        }

        foreach ($reasons as $reason) {
            if (! in_array($reason->rule, self::KEY_COVERED_RULES, true)) {
                return false;
            }
        }

        return true;
    }

    public function has(string $testFile): bool
    {
        return isset($this->reasons[$testFile]);
    }

    /** @return list<string> */
    public function testFiles(): array
    {
        $files = array_keys($this->reasons);
        sort($files);

        return $files;
    }

    /** @return array<string, list<Reason>> */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
