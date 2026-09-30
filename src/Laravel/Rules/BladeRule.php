<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Laravel\BladeReferences;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;

/**
 * Laravel-only rule (SPEC.md §7.2.5): a changed `.blade.php` file is walked with
 * `BladeReferences::ancestorsOf()` for every static reference chain (`@include`, `@extends`,
 * `@component`, `view('x')`, `<x-name`) up to the templates the graph knows; every test file
 * with an edge to one of those ancestors is affected.
 *
 * Additive: it applies to every changed template, one the graph already knows included (its
 * own dependents are PhpEdge's), and to a deleted one. A test that renders a page including
 * the template under a condition it did not meet has no edge to the template, and is still
 * one of the tests a change to it can reach; which ones it reaches is a function of the
 * templates' contents and of that test's own edges, never of whether some other test happened
 * to render it (`Select\NonEdgeInputs`' `blade@1` scope is this rule's claim, per test file).
 * A template with no ancestor anyone depends on is left for the watch patterns.
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
        $remaining = array_fill_keys($context->remaining, true);

        foreach ($context->changed as $rel) {
            if (! BladeReferences::isBladePath($rel)) {
                continue;
            }

            $matched = false;

            foreach (BladeReferences::ancestorsOf($rel, $context->projectRoot) as $ancestor) {
                foreach ($graph->testFilesDependingOn($ancestor) as $testFile) {
                    $context->selection->add($testFile, new Reason($this->name(), $rel, $ancestor));
                    $matched = true;
                }
            }

            if ($matched && isset($remaining[$rel])) {
                $context->consume($rel);
            }
        }
    }
}
