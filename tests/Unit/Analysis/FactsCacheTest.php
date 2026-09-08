<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Analysis;

use Manuglopez\Replay\Analysis\DeclarationScanner;
use Manuglopez\Replay\Analysis\FactsCache;
use Manuglopez\Replay\Tests\Support\CountingStream;
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
    public function a_comment_only_edit_re_parses_because_the_payload_is_line_ranges(): void
    {
        // This used to be keyed by Cache\ContentHash::of(), which discards comments and
        // whitespace — the right key for the content key and the wrong one here, because
        // FileFacts::$bodies is a list of LINE RANGES. Adding a docblock produced a cache
        // hit and served ranges describing a file that no longer existed.
        $this->write('src/Widget.php', <<<'PHP'
            <?php
            namespace App;
            class Widget
            {
                public function value(): int { return 41; }
            }
            PHP);

        $first = new FactsCache($this->stateDir, $this->root);
        self::assertSame([[5, 5]], $first->forRelative('src/Widget.php')->bodies);

        $this->write('src/Widget.php', <<<'PHP'
            <?php
            namespace App;
            class Widget
            {
                /**
                 * The answer, minus one.
                 *
                 * @return int
                 */
                public function value(): int { return 41; }
            }
            PHP);

        $fresh = new FactsCache($this->stateDir, $this->root);
        $facts = $fresh->forRelative('src/Widget.php');

        self::assertSame([[10, 10]], $facts->bodies, 'the served ranges must describe the file on disk');
        self::assertTrue($facts->coversAnyBodyLine([10]));
        self::assertSame(['hits' => 0, 'misses' => 1], $fresh->stats());
    }

    #[Test]
    public function deleting_comment_lines_does_not_slide_a_load_time_line_into_a_stale_range(): void
    {
        // The same bug with the opposite sign: the file shrinks by two comment lines, the
        // top-level `return` lands on line 6 — exactly where the stale entry says a function
        // body is — and the file is credited as behavioural to whichever test loaded it
        // first. Under the old key both versions hash the same.
        $this->write('src/Registry.php', "<?php\n// pad\n// pad\nfunction h()\n{\n    return 1;\n}\nreturn ['x' => 1];\n");

        self::assertSame([[6, 6]], (new FactsCache($this->stateDir, $this->root))->forRelative('src/Registry.php')->bodies);

        $this->write('src/Registry.php', "<?php\nfunction h()\n{\n    return 1;\n}\nreturn ['x' => 1];\n");

        $fresh = new FactsCache($this->stateDir, $this->root);
        $facts = $fresh->forRelative('src/Registry.php');

        self::assertSame([[4, 4]], $facts->bodies);
        self::assertFalse($facts->coversAnyBodyLine([6]), 'line 6 is the top-level return, not a body');
        self::assertSame(['hits' => 0, 'misses' => 1], $fresh->stats());
    }

    #[Test]
    public function the_file_is_read_exactly_once(): void
    {
        // Two reads left a window — codegen, an `artisan` publish, an editor save, a
        // `git checkout` while Paratest workers scan — in which version B's facts were
        // stored under version A's hash, permanently, for every machine sharing the state
        // directory. Here the second read would see a body two lines further down.
        CountingStream::register();
        CountingStream::$versions = [
            "<?php\nnamespace App;\nclass W { public function v(): int { return 1; } }\n",
            "<?php\nnamespace App;\n/**\n * doc\n */\nclass W { public function v(): int { return 1; } }\n",
        ];

        try {
            $facts = (new FactsCache($this->stateDir, $this->root))->for('counting://x/W.php');

            self::assertSame(1, CountingStream::$opens);
            self::assertSame([[3, 3]], $facts->bodies, 'the facts must describe the bytes that were hashed');
        } finally {
            CountingStream::unregister();
        }
    }

    #[Test]
    public function a_failed_scan_is_never_written_to_the_cache(): void
    {
        // `unparseable` covers a syntax error and any swallowed Throwable alike — EMFILE
        // under N Paratest workers, memory pressure. Storing it made declarationOnly()
        // false forever for that content, so a genuinely declaration-only enum could never
        // enter the index or get a static edge again short of `prune --all`.
        $this->write('src/Broken.php', "<?php\nnamespace App;\nfinal class { function ( }\n");

        $cache = new FactsCache($this->stateDir, $this->root);

        self::assertFalse($cache->forRelative('src/Broken.php')->parsed);
        self::assertSame([], glob($this->stateDir . '/analysis/*/*/*.json') ?: []);
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
