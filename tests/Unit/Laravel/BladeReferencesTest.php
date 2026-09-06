<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Laravel;

use Manuglopez\Replay\Laravel\BladeReferences;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class BladeReferencesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('blade-references');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    public function test_returns_empty_when_there_is_no_views_directory(): void
    {
        self::assertSame([], BladeReferences::ancestorsOf('resources/views/partials/x.blade.php', $this->root));
    }

    public function test_finds_a_direct_include_ancestor(): void
    {
        $this->write('resources/views/partials/x.blade.php', '<div>x</div>');
        $this->write('resources/views/layout.blade.php', "@include('partials.x')");
        $this->write('resources/views/unrelated.blade.php', '<p>nothing here</p>');

        self::assertSame(
            ['resources/views/layout.blade.php'],
            BladeReferences::ancestorsOf('resources/views/partials/x.blade.php', $this->root),
        );
    }

    public function test_finds_a_transitive_ancestor_through_extends(): void
    {
        $this->write('resources/views/partials/x.blade.php', '<div>x</div>');
        $this->write('resources/views/layout.blade.php', "@include('partials.x')");
        $this->write('resources/views/page.blade.php', "@extends('layout')");

        self::assertEqualsCanonicalizing(
            ['resources/views/layout.blade.php', 'resources/views/page.blade.php'],
            BladeReferences::ancestorsOf('resources/views/partials/x.blade.php', $this->root),
        );
    }

    public function test_recognises_view_helper_and_view_make_references(): void
    {
        $this->write('resources/views/partials/x.blade.php', '<div>x</div>');
        $this->write('resources/views/a.blade.php', "{{ view('partials.x') }}");
        $this->write('resources/views/b.blade.php', "{!! View::make('partials.x') !!}");

        self::assertEqualsCanonicalizing(
            ['resources/views/a.blade.php', 'resources/views/b.blade.php'],
            BladeReferences::ancestorsOf('resources/views/partials/x.blade.php', $this->root),
        );
    }

    public function test_recognises_a_matching_component_tag(): void
    {
        $this->write('resources/views/components/card.blade.php', '<div>card</div>');
        $this->write('resources/views/dashboard.blade.php', '<x-card :title="$title" />');

        self::assertSame(
            ['resources/views/dashboard.blade.php'],
            BladeReferences::ancestorsOf('resources/views/components/card.blade.php', $this->root),
        );
    }

    public function test_a_component_tag_prefix_does_not_falsely_match_a_longer_name(): void
    {
        $this->write('resources/views/components/card.blade.php', '<div>card</div>');
        $this->write('resources/views/dashboard.blade.php', '<x-card-list />');

        self::assertSame([], BladeReferences::ancestorsOf('resources/views/components/card.blade.php', $this->root));
    }

    public function test_returns_empty_when_nothing_references_the_blade_file(): void
    {
        $this->write('resources/views/partials/x.blade.php', '<div>x</div>');
        $this->write('resources/views/unrelated.blade.php', '<p>nothing here</p>');

        self::assertSame([], BladeReferences::ancestorsOf('resources/views/partials/x.blade.php', $this->root));
    }

    public function test_is_blade_path(): void
    {
        self::assertTrue(BladeReferences::isBladePath('resources/views/welcome.blade.php'));
        self::assertFalse(BladeReferences::isBladePath('resources/views/welcome.php'));
        self::assertFalse(BladeReferences::isBladePath('app/View/welcome.blade.php'));
    }

    private function write(string $relative, string $content): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
    }
}
