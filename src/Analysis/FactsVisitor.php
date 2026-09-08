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

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\Namespace_ && $node->name !== null) {
            $this->namespaces[$node->name->toString()] = true;
        }

        if ($node instanceof Node\Stmt\ClassLike && $node->namespacedName !== null) {
            $this->declares[$node->namespacedName->toString()] = true;
        }

        if ($node instanceof Node\Name) {
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
     * The inclusive line span of `$node`'s body, or nothing when it has none.
     *
     * Three cases, and they are not the same thing:
     *
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
     *    answer does not depend on which line a given driver picks: for a *named* function
     *    or method no line in that span can execute at load time (signature, default
     *    arguments, promoted properties and return types are all call-time; attributes need
     *    reflection), which the same probe confirms — the declaration line reads
     *    "not executed" after a plain `require`.
     *  - a closure or arrow function with an empty body — deliberately still nothing. Its
     *    span includes the line the closure is *created* on, which does run at file load, so
     *    recording it would make `return ['cb' => function () {}]` look behavioural on the
     *    very line its load produces. An empty closure body has no code to lose.
     *
     * Arrow functions with a body are also skipped: php-parser synthesises a `return` with
     * no line information, so `getStartLine()` is -1 — see the {@see FileFacts} docblock.
     */
    private function recordBody(Node\FunctionLike $node): void
    {
        $stmts = $node->getStmts();

        if ($stmts === null) {
            return;
        }

        if ($stmts === []) {
            if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
                $this->recordRange($node->getStartLine(), $node->getEndLine());
            }

            return;
        }

        $this->recordRange($stmts[0]->getStartLine(), $stmts[count($stmts) - 1]->getEndLine());
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
