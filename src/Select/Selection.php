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
