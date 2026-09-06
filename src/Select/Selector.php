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

    public static function default(Graph $graph, TestPaths $testPaths, WatchPatterns $watch, string $projectRoot): self
    {
        return new self($graph, $testPaths, $watch, $projectRoot, [
            new Rules\PhpEdgeRule(),
            new Rules\TestFileRule(),
            new Rules\WatchRule(),
        ]);
    }

    /** @param list<string> $changed relative or absolute */
    public function affected(array $changed): Selection
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

        return $final;
    }
}
