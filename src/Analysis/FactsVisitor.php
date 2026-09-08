<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Analysis;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects everything {@see FileFacts} needs in a single pass, after php-parser's own
 * `NameResolver` has already rewritten every name-resolvable node into its fully-qualified
 * form (so imports, aliases, `::class`, `extends`/`implements`, type hints and `instanceof`
 * all arrive here already resolved).
 *
 * Runs after NameResolver in the traverser, never standalone.
 */
final class FactsVisitor extends NodeVisitorAbstract
{
    /** @var list<array{int, int}> */
    public array $bodies = [];

    /** @var array<string, true> */
    public array $declares = [];

    /** @var array<string, true> */
    public array $references = [];

    /** @var array<string, true> the file's own `namespace X;` names, subtracted from references */
    private array $namespaces = [];

    /**
     * The still-open ancestors of the node being entered, outermost first, never including
     * the node itself. Only {@see self::executesWhereItIsWritten()} reads it, and only to
     * tell an early-bound top-level `function` declaration from a conditional one.
     *
     * @var list<Node>
     */
    private array $ancestors = [];

    /**
     * `spl_object_id()` of the {@see Node\Name} nodes that can never name a class-like
     * declaration — see {@see self::markNonClassNames()}.
     *
     * @var array<int, true>
     */
    private array $notClassNames = [];

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_ && $node->name !== null) {
            $this->namespaces[$node->name->toString()] = true;
        }

        if ($node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null) {
            $this->declares[$node->namespacedName->toString()] = true;
        }

        $this->markNonClassNames($node);

        if ($node instanceof Node\Name && ! isset($this->notClassNames[spl_object_id($node)])) {
            $this->references[$node->toString()] = true;
        }

        // A class-name-shaped string literal: `class_exists('App\Foo')`, a container
        // binding, a `'App\Foo'` config value. Only ever matched against names some
        // declaration-only file actually declares ({@see StaticEdges::index()}), so an
        // unrelated string can never invent an edge on its own.
        if ($node instanceof Node\Scalar\String_ && self::looksLikeClassName($node->value)) {
            $this->references[ltrim($node->value, '\\')] = true;
        }

        if ($node instanceof Node\FunctionLike) {
            $this->recordBody($node);
        }

        $this->ancestors[] = $node;

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        array_pop($this->ancestors);

        return null;
    }

    /**
     * The collected references, minus the file's own namespace names.
     *
     * @return array<string, true>
     */
    public function references(): array
    {
        return array_diff_key($this->references, $this->namespaces);
    }

    /**
     * Excludes from `$references` the {@see Node\Name} nodes that can never name a
     * class-like declaration, and re-adds the one case php-parser leaves unresolved.
     *
     * `NameResolver` resolves every *usage* of a name, so an import statement is never the
     * only place a real dependency shows up — but collecting its raw names anyway is not
     * harmless:
     *
     *  - A grouped import (`use App\Sub\{Alpha, Beta};`) is stored as a prefix plus items
     *    NameResolver does NOT rewrite, so `App\Sub` — which names no class — and the bare
     *    `Alpha`/`Beta` — root-namespace names nobody wrote — both leaked into
     *    `$references`. The items are re-added joined to their prefix instead, which is
     *    both the name that was meant and the name every usage of them resolves to.
     *  - A function or constant lives in a different symbol table from a class, but its
     *    resolved name is shaped exactly like one (`App\Support\str` beside
     *    `App\Support\Str`) — and {@see StaticEdges} matches names case-insensitively,
     *    because PHP does. `use function`, `use const`, a call and a constant fetch are
     *    therefore all dropped.
     *
     * Nothing here touches `Stmt\TraitUse`, which is a real class reference and a different
     * node entirely.
     */
    private function markNonClassNames(Node $node): void
    {
        if ($node instanceof Node\Stmt\GroupUse) {
            $this->notClassNames[spl_object_id($node->prefix)] = true;
            $prefix = $node->prefix->toString();

            foreach ($node->uses as $use) {
                $this->notClassNames[spl_object_id($use->name)] = true;

                if (self::importsAClassName($node->type, $use->type)) {
                    $this->references[$prefix . '\\' . $use->name->toString()] = true;
                }
            }

            return;
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                if (! self::importsAClassName($node->type, $use->type)) {
                    $this->notClassNames[spl_object_id($use->name)] = true;
                }
            }

            return;
        }

        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $this->notClassNames[spl_object_id($node->name)] = true;

            return;
        }

        if ($node instanceof Node\Expr\ConstFetch) {
            $this->notClassNames[spl_object_id($node->name)] = true;
        }
    }

    /** An import item's effective kind — `TYPE_UNKNOWN` on the item defers to the statement. */
    private static function importsAClassName(int $statementType, int $itemType): bool
    {
        $effective = $itemType === Node\Stmt\Use_::TYPE_UNKNOWN ? $statementType : $itemType;

        return $effective === Node\Stmt\Use_::TYPE_NORMAL;
    }

    /**
     * The inclusive line span of `$node`'s body, or nothing when it has none.
     *
     * Four cases, and they are not the same thing:
     *
     *  - a **short property hook** (`get => (int) ($this->celsius * 1.8)`) — recorded from
     *    the hook's own expression. {@see Node\PropertyHook::getStmts()} synthesises the
     *    statement (`new Return_($this->body)`) with no attributes at all, so its
     *    `getStartLine()` is -1 and the generic path below dropped it silently: a
     *    hooks-only class scanned to no body range at all and classified as
     *    declaration-only, which is what a hooks-only class is not. Verified with xdebug
     *    3.5.3 that a short-hook line is reported executed when the property is accessed
     *    and *not* when the file is merely loaded — even with the whole hook written on the
     *    class declaration line — so the expression's span is call-time code exactly like a
     *    method body. Reading `$node->body` rather than `getStmts()` also avoids the
     *    `LogicException` the latter throws for a `set` hook with no `propertyName`
     *    attribute. (pcov 1.0.12 instruments no property hook in either direction; that is
     *    a driver limitation, and this classifier describes when PHP runs a line, never
     *    what a given driver reports — see the {@see FileFacts} docblock.)
     *  - `stmts === null` — an abstract or interface method. There is genuinely no code, and
     *    no driver can ever report a line for it. Nothing recorded.
     *  - `stmts === []` — a *concrete* body that happens to be empty
     *    (`public function __construct(public readonly int $x) {}`, common on readonly DTOs
     *    and value objects). This is call-time code with no statement to anchor on, and a
     *    driver does report a line for it: verified with pcov 8.4, a called empty body is
     *    credited to the closing-brace line — `getEndLine()` — for a same-line body, a bare
     *    `{}`, and a multi-line signature alike, and is reported *unexecuted* when the file
     *    is merely loaded. Treating it like the abstract case made such a file classify as
     *    declaration-only, and `Record\Recorder` then dropped its edge for every test,
     *    always. The whole node span is recorded rather than just `getEndLine()`, so the
     *    answer does not depend on which line a given driver picks.
     *  - a closure or arrow function with an empty body — deliberately still nothing. Its
     *    span includes the line the closure is *created* on, which does run at file load, so
     *    recording it would make `return ['cb' => function () {}]` look behavioural on the
     *    very line its load produces. An empty closure body has no code to lose.
     *
     * Whatever the case, a recorded range never *starts* on a line the declaration itself
     * executes on — {@see self::executesWhereItIsWritten()} is where that is decided, and
     * where the measurements behind it are.
     *
     * Arrow functions with a body are still skipped: php-parser synthesises a `return` with
     * no line information, so `getStartLine()` is -1 — see the {@see FileFacts} docblock.
     */
    private function recordBody(Node\FunctionLike $node): void
    {
        if ($node instanceof Node\PropertyHook && $node->body instanceof Node\Expr) {
            $this->recordRange($node->body->getStartLine(), $node->body->getEndLine());

            return;
        }

        $stmts = $node->getStmts();

        if ($stmts === null) {
            return;
        }

        if ($stmts === []) {
            if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
                $this->recordRange($this->firstBodyLine($node, $node->getStartLine()), $node->getEndLine());
            }

            return;
        }

        $this->recordRange(
            $this->firstBodyLine($node, $stmts[0]->getStartLine()),
            $stmts[count($stmts) - 1]->getEndLine(),
        );
    }

    /** `$first`, raised past the declaration's own line when that line runs at load time. */
    private function firstBodyLine(Node\FunctionLike $node, int $first): int
    {
        if (! $this->executesWhereItIsWritten($node)) {
            return $first;
        }

        return max($first, $node->getStartLine() + 1);
    }

    /**
     * Whether PHP runs an opcode on the very line `$node`'s declaration is written on —
     * which is what makes a body range starting there ambiguous, and defeats the whole
     * point of {@see FileFacts::coversAnyBodyLine()} for that file.
     *
     * Two shapes qualify, both established by measurement on PHP 8.4.23:
     *
     *  - **A closure or arrow function.** Creating one is an opcode on the line its
     *    `function`/`fn` keyword is on. `return ['resolver' => function () { return 42; }];`
     *    scanned to a body range covering that same line, and a bare `require` of the file
     *    reports the line covered under pcov *and* xdebug — so a one-line closure in a
     *    `return [...]` config file (logging config, container bindings, a route registry)
     *    counted as a *behavioural* dependency of whichever test loaded the file first and
     *    of no other: the exact first-loader-wins non-determinism this feature exists to
     *    remove. Only the empty-body case was guarded before.
     *  - **A conditionally declared `function`.** `if (! function_exists('h')) { function h()
     *    { return 1; } }` compiles to a runtime declaration opcode on the `function` line,
     *    and xdebug reports it executed on a bare `require`. A function declared at the top
     *    level of the file (or of a `namespace`/`declare` block) is early-bound instead, and
     *    both drivers agree its line is *not* covered at load — so the common one-line
     *    helper keeps its range. That is why the ancestors decide this, and not the shape of
     *    the line.
     *
     * A method never qualifies: nothing on a `ClassMethod`'s signature line — modifiers,
     * default arguments, promoted properties, return types — is anything but call-time, and
     * attributes need reflection. Verified: the declaration line reads "not executed" after
     * a plain `require`.
     */
    private function executesWhereItIsWritten(Node\FunctionLike $node): bool
    {
        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            return true;
        }

        if (! $node instanceof Node\Stmt\Function_) {
            return false;
        }

        foreach ($this->ancestors as $ancestor) {
            if (! $ancestor instanceof Node\Stmt\Namespace_ && ! $ancestor instanceof Node\Stmt\Declare_) {
                return true;
            }
        }

        return false;
    }

    private function recordRange(int $first, int $last): void
    {
        if ($first < 1 || $last < $first) {
            return;
        }

        $this->bodies[] = [$first, $last];
    }

    private static function looksLikeClassName(string $value): bool
    {
        if (! str_contains($value, '\\') || strlen($value) > 512) {
            return false;
        }

        return preg_match('/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)+$/', $value) === 1;
    }
}
