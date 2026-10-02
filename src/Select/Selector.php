<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Support\Paths;

/** Runs the rule chain over a set of changed files (SPEC.md §7.2). */
final class Selector
{
    /** @param list<Rule> $rules */
    public function __construct(
        private readonly Graph $graph,
        private readonly TestPaths $testPaths,
        private readonly WatchPatterns $watch,
        private readonly string $projectRoot,
        private readonly array $rules,
    ) {
    }

    /**
     * @param array{migration?: Rule, schema?: Rule, sibling?: Rule, blade?: Rule} $extraRules Laravel-only
     *        rules (SPEC.md §7.2, docs/INTERNALS.md "Laravel"), inserted in SPEC order:
     *        Migration and SchemaDump first, PhpEdge, TestFile, then Sibling and Blade, then Watch last.
     *        Absent keys are simply skipped, so `[]` (the default, and every non-Laravel
     *        project) reproduces the plain chain.
     */
    public static function default(
        Graph $graph,
        TestPaths $testPaths,
        WatchPatterns $watch,
        string $projectRoot,
        array $extraRules = [],
    ): self {
        return new self($graph, $testPaths, $watch, $projectRoot, [
            ...(isset($extraRules['migration']) ? [$extraRules['migration']] : []),
            ...(isset($extraRules['schema']) ? [$extraRules['schema']] : []),
            new Rules\PhpEdgeRule(),
            new Rules\TestFileRule(),
            ...(isset($extraRules['sibling']) ? [$extraRules['sibling']] : []),
            ...(isset($extraRules['blade']) ? [$extraRules['blade']] : []),
            new Rules\WatchRule(),
        ]);
    }

    /**
     * @param list<string> $changed relative or absolute
     * @param string|null $base the commit `$changed` was diffed from, for a rule that compares
     *        a file's content before and after (`Laravel\Rules\SchemaDumpRule`); null when the
     *        change set has no such base
     */
    public function affected(array $changed, ?string $base = null): Selection
    {
        $remaining = [];

        foreach ($changed as $path) {
            $rel = Paths::relative($this->projectRoot, $path);

            if ($rel !== null) {
                $remaining[] = $rel;
            }
        }

        $remaining = array_values(array_unique($remaining));

        $sourcePhpChanged = false;

        foreach ($remaining as $rel) {
            if (str_ends_with($rel, '.php') && ! $this->testPaths->isTestFile($rel)) {
                $sourcePhpChanged = true;

                break;
            }
        }

        $selection = new Selection($sourcePhpChanged);

        $context = new Context(
            $this->graph,
            $this->projectRoot,
            $this->testPaths,
            $this->watch,
            $remaining,
            $selection,
            $base,
        );

        foreach ($this->rules as $rule) {
            $rule->apply($context);
        }

        return $this->dropMissingTestFiles($selection);
    }

    private function dropMissingTestFiles(Selection $selection): Selection
    {
        $final = new Selection($selection->sourcePhpChanged);

        foreach ($selection->reasons() as $testFile => $reasons) {
            if (! is_file(Paths::join($this->projectRoot, $testFile))) {
                continue;
            }

            foreach ($reasons as $reason) {
                $final->add($testFile, $reason);
            }
        }

        foreach ($selection->notes() as $note) {
            $final->note($note);
        }

        return $final;
    }
}
