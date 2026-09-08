<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Analysis;

use Manuglopez\Replay\Analysis\DeclarationScanner;
use PHPUnit\Framework\Attributes\Group;
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
