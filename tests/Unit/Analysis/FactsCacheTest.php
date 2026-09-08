<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Analysis;

use Manuglopez\Replay\Analysis\DeclarationScanner;
use Manuglopez\Replay\Analysis\FactsCache;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('static-declaration-edges')]
final class FactsCacheTest extends TestCase
{
    private string $root;

    private string $stateDir;

    protected function setUp(): void
    {
        $this->root = TempDir::make('facts-root');
        $this->stateDir = TempDir::make('facts-state');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
        TempDir::remove($this->stateDir);
    }

    #[Test]
    public function it_parses_once_and_then_serves_from_memory(): void
    {
        $this->write('src/Decision.php', "<?php\nnamespace App;\nenum Decision { case A; }\n");
        $cache = new FactsCache($this->stateDir, $this->root);

        $first = $cache->forRelative('src/Decision.php');
        $second = $cache->forRelative('src/Decision.php');

        self::assertTrue($first->declarationOnly());
        self::assertSame(['App\Decision'], $second->declares);
        self::assertSame(['hits' => 0, 'misses' => 1], $cache->stats());
    }

    #[Test]
    public function a_second_process_reads_the_entry_off_disk(): void
    {
        $this->write('src/Decision.php', "<?php\nnamespace App;\nenum Decision { case A; }\n");

        (new FactsCache($this->stateDir, $this->root))->forRelative('src/Decision.php');
        $fresh = new FactsCache($this->stateDir, $this->root);

        self::assertSame(['App\Decision'], $fresh->forRelative('src/Decision.php')->declares);
        self::assertSame(['hits' => 1, 'misses' => 0], $fresh->stats());
    }

    #[Test]
    public function a_comment_only_edit_reuses_the_cached_entry(): void
    {
        // The key is Cache\ContentHash::of(), the same normalised hash the content key is
        // built from, so comments and whitespace do not invalidate a file's facts.
        $this->write('src/Decision.php', "<?php\nnamespace App;\nenum Decision { case A; }\n");
        (new FactsCache($this->stateDir, $this->root))->forRelative('src/Decision.php');

        $this->write('src/Decision.php', "<?php\n// a new comment\nnamespace App;\nenum Decision { case A; }\n");
        $fresh = new FactsCache($this->stateDir, $this->root);
        $facts = $fresh->forRelative('src/Decision.php');

        self::assertSame(['App\Decision'], $facts->declares);
        self::assertSame(['hits' => 1, 'misses' => 0], $fresh->stats());
    }

    #[Test]
    public function a_real_edit_re_parses(): void
    {
        $this->write('src/Decision.php', "<?php\nnamespace App;\nenum Decision { case A; }\n");
        (new FactsCache($this->stateDir, $this->root))->forRelative('src/Decision.php');

        $this->write('src/Decision.php', "<?php\nnamespace App;\nenum Decision { case A; public function x(): int { return 1; } }\n");
        $fresh = new FactsCache($this->stateDir, $this->root);
        $facts = $fresh->forRelative('src/Decision.php');

        self::assertFalse($facts->declarationOnly());
        self::assertSame(['hits' => 0, 'misses' => 1], $fresh->stats());
    }

    #[Test]
    public function an_unreadable_file_is_unparseable_and_is_not_written_to_the_cache(): void
    {
        $cache = new FactsCache($this->stateDir, $this->root);

        $facts = $cache->forRelative('src/Missing.php');

        self::assertFalse($facts->parsed);
        self::assertSame(['hits' => 0, 'misses' => 0], $cache->stats());
        self::assertDirectoryDoesNotExist($this->stateDir . '/analysis');
    }

    #[Test]
    public function a_blade_template_is_never_classified(): void
    {
        // php-parser happily reads a Blade template as inline HTML and reports no function
        // bodies, which would make it look declaration-only and let the recorder drop the
        // edge `Laravel\BladeTracker` linked by hand. Templates are simply not this
        // classifier's business.
        $this->write('resources/views/mail.blade.php', "@extends('layouts.app')\n@section('body')\n@endsection\n");
        $cache = new FactsCache($this->stateDir, $this->root);

        $facts = $cache->forRelative('resources/views/mail.blade.php');

        self::assertFalse($facts->parsed);
        self::assertFalse($facts->declarationOnly());
        self::assertSame(['hits' => 0, 'misses' => 0], $cache->stats());
    }

    #[Test]
    public function it_writes_one_content_addressed_file_per_distinct_content(): void
    {
        $this->write('src/A.php', "<?php\nnamespace App;\nenum A { case X; }\n");
        $this->write('src/B.php', "<?php\nnamespace App;\nenum A { case X; }\n");
        $this->write('src/C.php', "<?php\nnamespace App;\nenum C { case X; }\n");

        $cache = new FactsCache($this->stateDir, $this->root);
        $cache->forRelative('src/A.php');
        $cache->forRelative('src/B.php');
        $cache->forRelative('src/C.php');

        self::assertCount(2, glob($this->stateDir . '/analysis/*/*/*.json') ?: []);
    }

    #[Test]
    public function the_cache_path_carries_the_classification_rules_version(): void
    {
        // Without this segment a change to the classification rules would keep serving the
        // old verdict for every unchanged file on every machine — a silent stale
        // classification (DeclarationScanner::RULES_VERSION).
        $this->write('src/Decision.php', "<?php\nnamespace App;\nenum Decision { case A; }\n");

        (new FactsCache($this->stateDir, $this->root))->forRelative('src/Decision.php');

        self::assertDirectoryExists($this->stateDir . '/analysis/v' . DeclarationScanner::RULES_VERSION);
        self::assertSame([], glob($this->stateDir . '/analysis/*.json') ?: []);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $content);
    }
}
