<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Laravel\Rules;

use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Laravel\LangFiles;
use Manuglopez\Replay\Laravel\LangPath;
use Manuglopez\Replay\Select\Context;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\ResiduePatterns;
use Manuglopez\Replay\Select\Rule;
use Manuglopez\Replay\Support\Paths;

/**
 * Laravel-only rule, registered with `translations => 'per-key'` (the default): a changed
 * language file (`lang/**`, `resources/lang/**`, {@see LangFiles::classify()}) selects the
 * test files that resolved one of its changed keys in its locale.
 *
 * While recording, `Laravel\RecordingTranslator` links every test file to the
 * `<locale>:<key>` pairs its translator was asked for, one per locale of the fallback chain,
 * and marks with `*` a file whose translations could not be traced (`Lang::swap()`, a mock);
 * `Cache\Graph::testTranslations()` holds them. Coverage cannot do this: a JSON file is
 * never executed, and a PHP one is executed by every test that loads its group, whichever
 * key it reads.
 *
 * Each changed file is compared with its version at the change set's base
 * (`Context::$base`, read with `git show`), both flattened without running anything
 * ({@see LangFiles::changedKeys()}). A test file is selected when it recorded
 * `<locale of the file>:<k>` for a k that {@see LangFiles::matches()} a changed key (the same
 * key, a group or subtree holding it, or a key under it), and every test file marked `*` is
 * selected by any key change. A change of formatting, order or comments only selects nothing
 * and says so in a note.
 *
 * What cannot be compared selects every test of the testsuites (the targets of
 * {@see ResiduePatterns::targetsFor()}), like `lang/**` did before, but for that file only,
 * and `--explain` says why: a vendor package's translations or a file under a language root
 * that is not a translation file (whose loading this package does not follow), a file that
 * cannot be read without running it (invalid JSON, PHP that is not a literal array), a file
 * created or deleted since the base, no base at all, or a graph that recorded no translation
 * key (one recorded before this rule existed: `Cache\Fingerprint` makes the first pass after
 * an update record everything again, so this lasts one pass).
 *
 * Additive, like {@see BladeRule}: it reads every changed path (`Context::$changed`), so a
 * test file that also executes a PHP language file keeps the selection `PhpEdge` gave it, and
 * consumes every language path from the remaining ones, so that neither a fallback nor, with
 * `static_declaration_edges`, the residue runs everything for it again.
 *
 * `Select\NonEdgeInputs` mirrors it exactly (scopes `trans@1`, `lang:uncomparable@1` and
 * `lang@1`): after a change under a language root, a test file's digest moves if and only if
 * this rule selects it, or a stamped result would stop being served for nothing.
 */
final class TranslationRule implements Rule
{
    public const UNTRACKABLE = 'resolved translations untrackably';

    public const FORMATTING_ONLY = 'only formatting/order/comments: selects nothing';

    /** How many changed keys a reason names before it counts the rest. */
    private const KEYS_SHOWN = 3;

    public function name(): string
    {
        return 'Translation';
    }

    public function apply(Context $context): void
    {
        $git = null;

        foreach ($context->changed as $rel) {
            $path = LangFiles::classify($rel);

            if ($path === null) {
                continue;
            }

            $context->consume($rel);
            $compared = $this->compare($context, $rel, $path, $git);

            if (is_string($compared)) {
                $this->selectEveryTest($context, $rel, $compared);

                continue;
            }

            [$locale, $keys] = $compared;

            if ($keys === []) {
                $context->selection->note(new Reason($this->name(), $rel, self::FORMATTING_ONLY));

                continue;
            }

            $this->selectByKey($context, $rel, $locale, $keys);
        }
    }

    /**
     * Why a file under a language root cannot be compared, or its locale and changed keys.
     *
     * @return string|array{string, list<string>}
     */
    private function compare(Context $context, string $rel, LangPath $path, ?Git &$git): string|array
    {
        if ($path->kind === LangPath::VENDOR) {
            return 'vendor package translations';
        }

        if ($path->kind === LangPath::OTHER || $path->locale === null) {
            return 'not a translation file';
        }

        if ($context->graph->testTranslations() === []) {
            return 'no translation keys recorded yet';
        }

        if ($context->base === null) {
            return 'no base to compare with';
        }

        $absolute = Paths::join($context->projectRoot, $rel);
        $new = is_file($absolute) ? @file_get_contents($absolute) : null;

        if ($new === false) {
            return 'cannot be read';
        }

        $git ??= new Git($context->projectRoot);
        $old = $git->show($context->base, $rel);

        if ($old === null) {
            return $new === null ? 'not in the working tree nor at the base' : 'new file: no version at the base';
        }

        if ($new === null) {
            return 'deleted file';
        }

        $keys = LangFiles::changedKeys($old, $new, $path);

        return $keys === null ? 'cannot be read without running it' : [$path->locale, $keys];
    }

    /** @param list<string> $keys the changed keys, sorted */
    private function selectByKey(Context $context, string $rel, string $locale, array $keys): void
    {
        $prefix = $locale . ':';
        $untrackable = [];

        foreach ($context->graph->testTranslations() as $testFile => $entries) {
            $testFile = (string) $testFile;
            $reached = [];

            foreach ($entries as $entry) {
                if ($entry === '*') {
                    $untrackable[] = $testFile;

                    continue;
                }

                if (! str_starts_with($entry, $prefix)) {
                    continue;
                }

                $recorded = substr($entry, strlen($prefix));

                foreach ($keys as $key) {
                    if (LangFiles::matches($recorded, $key)) {
                        $reached[$key] = true;
                    }
                }
            }

            if ($reached !== []) {
                $context->selection->add($testFile, new Reason($this->name(), $rel, self::describe(array_keys($reached), $locale)));
            }
        }

        foreach ($untrackable as $testFile) {
            $context->selection->add($testFile, new Reason($this->name(), $rel, self::UNTRACKABLE));
        }
    }

    private function selectEveryTest(Context $context, string $rel, string $why): void
    {
        $targets = ResiduePatterns::targetsFor($context->testPaths);
        $reason = new Reason($this->name(), $rel, 'cannot compare (' . $why . '): every test');

        foreach ($context->watch->testsUnderDirectories($targets, $context->graph->allTestFiles()) as $testFile) {
            $context->selection->add($testFile, $reason);
        }

        // And, wherever it lives, every test file that resolved a translation: what this file
        // held, or holds now, may be what it read.
        foreach ($context->graph->testTranslations() as $testFile => $entries) {
            if ($entries !== []) {
                $context->selection->add((string) $testFile, $reason);
            }
        }
    }

    /** @param list<string|int> $keys in the order they changed (sorted) */
    private static function describe(array $keys, string $locale): string
    {
        $shown = array_map(static fn (string|int $key): string => '"' . $key . '"', array_slice($keys, 0, self::KEYS_SHOWN));
        $more = count($keys) - count($shown);

        return (count($keys) === 1 ? 'key ' : 'keys ') . implode(', ', $shown) . ($more > 0 ? ' and ' . $more . ' more' : '') . ' (' . $locale . ')';
    }
}
