<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Analysis;

use Manuglopez\Replay\Analysis\DeclarationScanner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('static-declaration-edges')]
final class DeclarationScannerTest extends TestCase
{
    private DeclarationScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new DeclarationScanner();
    }

    // -- declarationOnly() -----------------------------------------------------

    #[Test]
    public function an_enum_with_only_cases_is_declaration_only(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App\Enums;
            enum PublisherReviewDecision: string {
                case Approved = 'approved';
                case Rejected = 'rejected';
            }
            PHP);

        self::assertTrue($facts->parsed);
        self::assertTrue($facts->declarationOnly());
        self::assertSame([], $facts->bodies);
        self::assertSame(['App\Enums\PublisherReviewDecision'], $facts->declares);
    }

    #[Test]
    public function a_constants_only_class_is_declaration_only(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            final class Limits {
                public const MAX = 3;
                private array $seen = [];
            }
            PHP);

        self::assertTrue($facts->declarationOnly());
        self::assertSame(['App\Limits'], $facts->declares);
    }

    #[Test]
    public function an_interface_is_declaration_only_because_its_methods_have_no_body(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            interface Payable {
                public function pay(int $cents): void;
            }
            PHP);

        self::assertTrue($facts->declarationOnly());
        self::assertSame(['App\Payable'], $facts->declares);
    }

    #[Test]
    public function an_abstract_method_contributes_no_body_but_a_concrete_one_does(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            abstract class Base {
                abstract public function a(): void;
                public function b(): int {
                    return 1;
                }
            }
            PHP);

        self::assertFalse($facts->declarationOnly());
        self::assertSame([[6, 6]], $facts->bodies);
    }

    #[Test]
    public function a_return_array_config_file_is_declaration_only(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            return [
                'driver' => 'redis',
                'ttl' => 60,
            ];
            PHP);

        self::assertTrue($facts->declarationOnly());
        self::assertSame([], $facts->declares);
    }

    #[Test]
    public function an_arrow_function_in_a_config_file_keeps_it_declaration_only(): void
    {
        // php-parser synthesises a `return` statement for `fn () => ...` with no line
        // information, and the expression shares its line with the enclosing top-level
        // statement — counting it would make the file's load-time coverage look
        // behavioural on the very line the load produces.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            return [
                'resolver' => fn (int $x): int => $x + 1,
            ];
            PHP);

        self::assertTrue($facts->declarationOnly());
    }

    #[Test]
    public function a_multiline_closure_in_a_config_file_makes_it_behavioural(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            return [
                'resolver' => function (int $x): int {
                    return $x + 1;
                },
            ];
            PHP);

        self::assertFalse($facts->declarationOnly());
        self::assertSame([[4, 4]], $facts->bodies);
    }

    #[Test]
    public function an_empty_concrete_method_body_is_still_a_body(): void
    {
        // Regression: this used to be treated like an abstract method and the file came back
        // declaration-only, which made Record\Recorder drop its edge for every test forever.
        // Verified with pcov 8.4: a called empty body IS credited a line (the closing brace),
        // and is reported unexecuted when the file is merely loaded — it is call-time code.
        // Readonly DTOs and value objects have exactly this shape.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            final class Marker {
                public function __construct(public readonly int $id) {}
            }
            PHP);

        self::assertFalse($facts->declarationOnly());
        self::assertSame([[4, 4]], $facts->bodies);
        self::assertTrue($facts->coversAnyBodyLine([4]));
    }

    #[Test]
    public function an_empty_body_range_covers_a_multiline_signature(): void
    {
        // pcov credits the closing-brace line here; the whole node span is recorded so the
        // answer does not depend on which line a given driver picks.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            final class Marker {
                public function multi(
                    int $a,
                ): void {
                }
            }
            PHP);

        self::assertSame([[4, 7]], $facts->bodies);
        self::assertTrue($facts->coversAnyBodyLine([7]));
    }

    #[Test]
    public function an_empty_top_level_function_body_is_a_body_too(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            function noop() {}
            PHP);

        self::assertFalse($facts->declarationOnly());
        self::assertSame([[3, 3]], $facts->bodies);
    }

    #[Test]
    public function an_empty_closure_body_contributes_no_range(): void
    {
        // Deliberate: a closure's span includes the line it is *created* on, which does run
        // at file load, so recording it would make this config file look behavioural on the
        // very line its load produces. An empty closure body has no code to lose.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            return [
                'cb' => function () {},
            ];
            PHP);

        self::assertTrue($facts->declarationOnly());
        self::assertSame([], $facts->bodies);
    }

    #[Test]
    public function an_abstract_or_interface_method_is_still_not_a_body(): void
    {
        // The distinction the fix above turns on: `stmts === null` really has no code.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            abstract class Base {
                abstract public function a(): void;
            }
            PHP);

        self::assertTrue($facts->declarationOnly());
        self::assertSame([], $facts->bodies);
    }

    // -- bodies ----------------------------------------------------------------

    #[Test]
    public function a_body_range_spans_the_first_to_the_last_statement_not_the_signature(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            final class Greeter {
                public function greet(
                    string $name = 'x',
                ): string {
                    $trimmed = trim($name);

                    return $trimmed;
                }
            }
            PHP);

        self::assertSame([[7, 9]], $facts->bodies);
        self::assertTrue($facts->coversAnyBodyLine([9]));
        self::assertFalse($facts->coversAnyBodyLine([3, 4, 5, 6]));
    }

    // -- references ------------------------------------------------------------

    #[Test]
    public function it_resolves_references_from_imports_aliases_fqcn_class_const_and_type_hints(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App\Services;

            use App\Enums\Decision;
            use App\Enums\Other as Alias;
            use App\Contracts\Payable;

            final class Reviewer implements Payable {
                public function run(Decision $decision, \App\Support\Clock $clock): string {
                    $name = Alias::class;

                    if ($clock instanceof \App\Support\FrozenClock) {
                        return $name;
                    }

                    return \App\Support\Helper::NAME;
                }
            }
            PHP);

        foreach ([
            'App\Enums\Decision',
            'App\Enums\Other',
            'App\Contracts\Payable',
            'App\Support\Clock',
            'App\Support\FrozenClock',
            'App\Support\Helper',
        ] as $expected) {
            self::assertContains($expected, $facts->references, $expected . ' should be a reference');
        }
    }

    #[Test]
    public function it_resolves_references_from_extends_and_implements(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            use App\Contracts\Marker;
            final class Child extends \App\Base implements Marker {
                public function x(): void { $this->y(); }
            }
            PHP);

        self::assertContains('App\Base', $facts->references);
        self::assertContains('App\Contracts\Marker', $facts->references);
    }

    #[Test]
    public function it_treats_a_class_shaped_string_literal_as_a_reference(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            final class Registry {
                public function boot(): void {
                    if (class_exists('App\Enums\Decision')) {
                        $this->bind('\App\Support\Clock');
                    }

                    $this->tag('not.a.class');
                }
            }
            PHP);

        self::assertContains('App\Enums\Decision', $facts->references);
        self::assertContains('App\Support\Clock', $facts->references);
        self::assertNotContains('not.a.class', $facts->references);
    }

    #[Test]
    public function the_files_own_namespace_is_not_a_reference(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App\Enums;
            enum Decision { case A; }
            PHP);

        self::assertNotContains('App\Enums', $facts->references);
    }

    // -- bodies: lines the declaration itself runs on ---------------------------

    #[Test]
    public function a_one_line_closure_in_a_config_file_keeps_it_declaration_only(): void
    {
        // The body statement and the closure's creation site are the same line, and that
        // line runs on a bare `require` (verified under pcov 1.0.12 and xdebug 3.5.3).
        // Recording it made a logging config / container binding / route registry a
        // "behavioural" dependency of whichever test loaded the file first, and of no other.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php

            return ['resolver' => function () { return 42; }, 'other' => 'x'];
            PHP);

        self::assertSame([], $facts->bodies);
        self::assertTrue($facts->declarationOnly());
    }

    #[Test]
    public function a_closure_body_below_its_creation_line_is_still_a_body(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php

            return [
                'resolver' => function () {
                    return 42;
                },
            ];
            PHP);

        self::assertSame([[5, 5]], $facts->bodies);
    }

    #[Test]
    public function a_conditionally_declared_one_line_function_contributes_no_range(): void
    {
        // `if (! function_exists(...))` compiles to a runtime declaration opcode on the
        // `function` line, which xdebug reports as executed on a bare `require`.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php

            if (! function_exists('h')) {
                function h() { return 1; }
            }
            PHP);

        self::assertSame([], $facts->bodies);
    }

    #[Test]
    public function an_unconditional_one_line_function_keeps_its_range(): void
    {
        // The counterpart, and the reason the ancestors decide this: a top-level function is
        // early-bound, both drivers agree its line is not covered at load, and a helpers
        // file full of one-liners must not become declaration-only.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php

            function ty_a() { return 1; }
            PHP);

        self::assertSame([[3, 3]], $facts->bodies);

        $namespaced = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            function n() { return 1; }
            PHP);

        self::assertSame([[3, 3]], $namespaced->bodies);
    }

    // -- bodies: property hooks -------------------------------------------------

    #[Test]
    #[RequiresPhp('>= 8.4.0')]
    public function short_property_hooks_contribute_a_body_range(): void
    {
        // PropertyHook::getStmts() synthesises `new Return_($this->body)` with no attributes,
        // so its getStartLine() is -1 and the range was dropped: a hooks-only class scanned
        // to no body at all and classified as declaration-only. xdebug 3.5.3 reports lines 8
        // and 9 executed when the property is read/written, and neither at load.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            class Temp
            {
                public int $celsius = 0;

                public int $fahrenheit {
                    get => (int) ($this->celsius * 1.8 + 32);
                    set => $this->celsius = (int) (($value - 32) / 1.8);
                }
            }
            PHP);

        self::assertSame([[8, 8], [9, 9]], $facts->bodies);
        self::assertFalse($facts->declarationOnly());
    }

    #[Test]
    #[RequiresPhp('>= 8.4.0')]
    public function a_hook_written_on_the_class_declaration_line_is_still_a_body(): void
    {
        // The declaration line is not load-executed either, so there is nothing to guard
        // against here — verified with both drivers.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            class One { public int $v { get => 7; } }
            PHP);

        self::assertSame([[3, 3]], $facts->bodies);
    }

    #[Test]
    #[RequiresPhp('>= 8.4.0')]
    public function a_block_form_property_hook_still_records_its_statements(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            class Two
            {
                public int $celsius = 0;

                public int $fahrenheit {
                    get {
                        return (int) ($this->celsius * 1.8 + 32);
                    }
                }
            }
            PHP);

        self::assertSame([[9, 9]], $facts->bodies);
    }

    // -- references: what an import statement may contribute --------------------

    #[Test]
    public function a_grouped_import_contributes_its_items_joined_to_the_prefix(): void
    {
        // php-parser does not rewrite the items of a group use, so the raw names leaked:
        // `App\Sub` (which names no class) plus the bare `Alpha`/`Beta`, root-namespace
        // names nobody wrote, either of which could mint an edge to an unrelated file.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            use App\Sub\{Alpha, Beta};
            class Z {}
            PHP);

        self::assertSame(['App\Sub\Alpha', 'App\Sub\Beta'], $facts->references);
    }

    #[Test]
    public function a_function_or_constant_name_is_never_a_class_reference(): void
    {
        // Resolved, these are shaped exactly like class names — and StaticEdges matches
        // names case-insensitively, so `App\Support\str` would have reached a class
        // `App\Support\Str`.
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App\Tests;
            use function App\Support\str;
            use const App\Support\MAX;
            class T2 { public function y() { return str('a') . MAX . strlen('x'); } }
            PHP);

        self::assertSame([], $facts->references);
    }

    #[Test]
    public function a_trait_use_is_still_a_class_reference(): void
    {
        $facts = $this->scanner->scanSource(<<<'PHP'
            <?php
            namespace App;
            use App\Concerns\Sluggable;
            class M { use Sluggable; }
            PHP);

        self::assertSame(['App\Concerns\Sluggable'], $facts->references);
    }

    // -- unparseable -----------------------------------------------------------

    #[Test]
    public function a_syntax_error_yields_unparseable_and_never_declaration_only(): void
    {
        $facts = $this->scanner->scanSource('<?php final class { function ( }');

        self::assertFalse($facts->parsed);
        self::assertFalse($facts->declarationOnly(), 'unparseable must never be mistaken for "no bodies"');
        self::assertSame([], $facts->references);
    }

    #[Test]
    public function a_template_with_no_php_tag_parses_as_inline_html_with_no_bodies(): void
    {
        // Worth pinning: php-parser accepts a Blade template as one big inline-HTML
        // statement, so it comes back "declaration-only" rather than unparseable. That is
        // why templates are refused one level up, in FactsCache — see
        // FactsCacheTest::a_blade_template_is_never_classified().
        $facts = $this->scanner->scanSource("@extends('layouts.app')\n@section('body')\n@endsection\n");

        self::assertTrue($facts->parsed);
        self::assertSame([], $facts->bodies);
    }

    #[Test]
    public function a_missing_file_is_unparseable(): void
    {
        $facts = $this->scanner->scan('/definitely/not/here/Nope.php');

        self::assertFalse($facts->parsed);
        self::assertFalse($facts->declarationOnly());
    }
}
