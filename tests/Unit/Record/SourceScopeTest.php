<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Record;

use Manuglopez\Replay\Record\SourceScope;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SourceScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('source-scope');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    #[Test]
    public function it_contains_files_under_an_included_directory(): void
    {
        $this->mkdir('src');
        $this->touch('src/Foo.php');

        $scope = new SourceScope([$this->root . '/src'], []);

        self::assertTrue($scope->contains($this->root . '/src/Foo.php'));
    }

    #[Test]
    public function it_excludes_files_outside_every_included_directory(): void
    {
        $this->mkdir('src');
        $this->mkdir('other');
        $this->touch('other/Baz.php');

        $scope = new SourceScope([$this->root . '/src'], []);

        self::assertFalse($scope->contains($this->root . '/other/Baz.php'));
    }

    #[Test]
    public function an_exclude_wins_over_an_include_for_the_same_file(): void
    {
        $this->mkdir('src/vendor');
        $this->touch('src/vendor/Bar.php');

        $scope = new SourceScope([$this->root . '/src'], [$this->root . '/src/vendor']);

        self::assertFalse($scope->contains($this->root . '/src/vendor/Bar.php'));
        self::assertTrue($scope->contains($this->root . '/src/Other.php'));
    }

    #[Test]
    public function it_resolves_a_symlink_via_realpath_before_matching(): void
    {
        $this->mkdir('src');
        $this->touch('src/Foo.php');
        symlink($this->root . '/src/Foo.php', $this->root . '/link.php');

        $scope = new SourceScope([$this->root . '/src'], []);

        self::assertTrue($scope->contains($this->root . '/link.php'));
    }

    #[Test]
    public function it_still_excludes_a_nonexistent_file_under_an_excluded_directory(): void
    {
        $scope = new SourceScope([$this->root . '/src'], [$this->root . '/src/vendor']);

        self::assertFalse($scope->contains($this->root . '/src/vendor/DoesNotExist.php'));
    }

    #[Test]
    public function from_project_root_excludes_nested_noise_but_keeps_the_rest_of_the_parent_directory(): void
    {
        $this->mkdir('storage/logs');
        $this->touch('storage/logs/laravel.log');
        $this->mkdir('storage/app');
        $this->touch('storage/app/file.txt');

        $scope = SourceScope::fromProjectRoot($this->root);

        self::assertFalse($scope->contains($this->root . '/storage/logs/laravel.log'));
        self::assertTrue($scope->contains($this->root . '/storage/app/file.txt'));
    }

    #[Test]
    public function from_project_root_only_includes_non_noise_top_level_directories(): void
    {
        foreach (['src', 'tests', 'vendor', 'node_modules', '.git'] as $dir) {
            $this->mkdir($dir);
        }
        $this->mkdir('storage/logs');

        $scope = SourceScope::fromProjectRoot($this->root);

        $real = realpath($this->root);
        self::assertNotFalse($real);

        $includes = $scope->includes();

        self::assertContains($real . '/src', $includes);
        self::assertContains($real . '/tests', $includes);
        self::assertContains($real . '/storage', $includes);
        self::assertNotContains($real . '/vendor', $includes);
        self::assertNotContains($real . '/node_modules', $includes);
        self::assertNotContains($real . '/.git', $includes);

        self::assertTrue($scope->contains($real . '/src/Anything.php'));
        self::assertFalse($scope->contains($real . '/vendor/Anything.php'));
        self::assertFalse($scope->contains($real . '/storage/logs/laravel.log'));
    }

    private function mkdir(string $relative): void
    {
        $path = $this->root . '/' . $relative;

        if (! is_dir($path)) {
            self::assertTrue(mkdir($path, 0o775, true));
        }
    }

    private function touch(string $relative): void
    {
        TempDir::write($this->root . '/' . $relative, '<?php');
    }
}
