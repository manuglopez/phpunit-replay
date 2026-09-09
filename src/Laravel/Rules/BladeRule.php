<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Laravel\BladeReferences;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * Laravel-only rule (SPEC.md §7.2.5): a changed `.blade.php` file unknown to the graph is
 * walked with `BladeReferences::ancestorsOf()` for every static reference chain
 * (`@include`, `@extends`, `@component`, `view('x')`, `<x-name`) up to a Blade file the graph
 * does know about; every test file with an edge to one of those ancestors is affected. A
 * Blade file with no known ancestor is left unconsumed for `WatchRule`.
 */
final class BladeRule implements Rule
{
    public function name(): string
    {
        return 'Blade';
    }

    public function apply(Context $context): void
    {
        $graph = $context->graph;

        foreach ($context->remaining as $rel) {
            if ($graph->fileId($rel) !== null) {
                continue;
            }

            if (! BladeReferences::isBladePath($rel)) {
                continue;
            }

            if (! is_file(Paths::join($context->projectRoot, $rel))) {
                continue;
            }

            $matched = false;

            foreach (BladeReferences::ancestorsOf($rel, $context->projectRoot) as $ancestor) {
                if ($graph->fileId($ancestor) === null) {
                    continue;
                }

                foreach ($graph->testFilesDependingOn($ancestor) as $testFile) {
                    $context->selection->add($testFile, new Reason($this->name(), $rel, $ancestor));
                    $matched = true;
                }
            }

            if ($matched) {
                $context->consume($rel);
            }
        }
    }
}
