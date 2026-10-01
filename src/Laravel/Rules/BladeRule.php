<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Laravel\BladeReferences;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\Rule;

/**
 * Laravel-only rule (SPEC.md §7.2.5): a changed `.blade.php` file is walked with
 * `BladeReferences::ancestorsOf()` for every static reference chain (`@include`,
 * `@includeFirst`, `@extends`, `@component`, `view('x')`, `<x-name`) up to the templates the
 * graph knows; every test file with an edge to one of those ancestors is affected.
 *
 * Additive: it applies to every changed template, one the graph already knows included (its
 * own dependents are PhpEdge's), and to a deleted one. A test that renders a page including
 * the template under a condition it did not meet has no edge to the template, and is still
 * one of the tests a change to it can reach; which ones it reaches is a function of the
 * templates' contents and of that test's own edges, never of whether some other test happened
 * to render it (`Select\NonEdgeInputs`' `blade@3` scope is this rule's claim, per test file).
 * A template this rule finds no such test for is left unconsumed, for the
 * `resources/views/**` fallback (`Select\WatchDefaults\Laravel`), which runs everything.
 *
 * The references come from `BladeReferences::referenceMap()`, resolved once per pass for all
 * the changed templates (`ancestorsOfEach()`) and persisted under the state directory
 * (`$cacheFile`): many changed templates no longer read every template once each.
 */
final class BladeRule implements Rule
{
    public function __construct(private readonly ?string $cacheFile = null)
    {
    }

    public function name(): string
    {
        return 'Blade';
    }

    public function apply(Context $context): void
    {
        $graph = $context->graph;
        $remaining = array_fill_keys($context->remaining, true);
        $templates = array_values(array_filter($context->changed, BladeReferences::isBladePath(...)));

        foreach (BladeReferences::ancestorsOfEach($templates, $context->projectRoot, $this->cacheFile) as $rel => $ancestors) {
            $rel = (string) $rel;
            $matched = false;

            foreach ($ancestors as $ancestor) {
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

    /** `<stateDir>/blade-references.json`: shared with `Select\NonEdgeInputs`. */
    public static function cacheFileIn(?string $stateDir): ?string
    {
        return $stateDir === null ? null : rtrim($stateDir, '/') . '/blade-references.json';
    }
}
