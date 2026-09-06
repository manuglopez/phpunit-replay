<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Change;

use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Change\LastRunTree;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class LastRunTreeTest extends TestCase
{
    public function test_save_and_load_round_trip(): void
    {
        $stateDir = TempDir::make('state');

        $original = new LastRunTree('main', str_repeat('a', 40), ['a.php' => 'hash-a', 'b.php' => 'hash-b'], 1_700_000_000);

        self::assertTrue($original->save($stateDir));

        $loaded = LastRunTree::load($stateDir);

        self::assertNotNull($loaded);
        self::assertSame($original->branch, $loaded->branch);
        self::assertSame($original->sha, $loaded->sha);
        self::assertSame($original->tree, $loaded->tree);
        self::assertSame($original->finishedAt, $loaded->finishedAt);

        TempDir::remove($stateDir);
    }

    public function test_save_creates_the_state_directory_when_missing(): void
    {
        $stateDir = TempDir::make('state').'/nested/deeper';

        $tree = new LastRunTree('main', null, [], 123);

        self::assertTrue($tree->save($stateDir));
        self::assertFileExists($stateDir.'/last-run.json');

        TempDir::remove(dirname($stateDir, 2));
    }

    public function test_load_returns_null_when_the_file_is_missing(): void
    {
        $stateDir = TempDir::make('state');

        self::assertNull(LastRunTree::load($stateDir));

        TempDir::remove($stateDir);
    }

    public function test_from_json_returns_null_for_garbage(): void
    {
        self::assertNull(LastRunTree::fromJson('not json at all'));
        self::assertNull(LastRunTree::fromJson('[]'));
        self::assertNull(LastRunTree::fromJson('null'));
        self::assertNull(LastRunTree::fromJson('{"branch":"main"}'));
        self::assertNull(LastRunTree::fromJson('{"branch":1,"sha":null,"tree":{},"finishedAt":1}'));
        self::assertNull(LastRunTree::fromJson('{"branch":"main","sha":null,"tree":{"a":1},"finishedAt":1}'));
        self::assertNull(LastRunTree::fromJson('{"branch":"main","sha":null,"tree":{},"finishedAt":"soon"}'));
        self::assertNull(LastRunTree::fromJson('{"branch":"main","sha":123,"tree":{},"finishedAt":1}'));
    }

    public function test_from_json_accepts_a_valid_document(): void
    {
        $tree = LastRunTree::fromJson('{"branch":"main","sha":"deadbeef","tree":{"a.php":"h1"},"finishedAt":42}');

        self::assertNotNull($tree);
        self::assertSame('main', $tree->branch);
        self::assertSame('deadbeef', $tree->sha);
        self::assertSame(['a.php' => 'h1'], $tree->tree);
        self::assertSame(42, $tree->finishedAt);
    }

    public function test_to_json_round_trips_a_null_sha_and_empty_tree(): void
    {
        $tree = new LastRunTree('main', null, [], 5);
        $decoded = LastRunTree::fromJson($tree->toJson());

        self::assertNotNull($decoded);
        self::assertSame('main', $decoded->branch);
        self::assertNull($decoded->sha);
        self::assertSame([], $decoded->tree);
        self::assertSame(5, $decoded->finishedAt);
    }

    public function test_filter_unchanged_drops_dirty_file_untouched_since_last_run(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'original');
        $repo->commitAll('initial');

        $repo->write('a.txt', 'dirty content');

        $changedFiles = new ChangedFiles($repo->root);
        $dirtyHash = $changedFiles->currentHash('a.txt');
        self::assertNotNull($dirtyHash);

        $lastRun = new LastRunTree('main', $repo->sha(), ['a.txt' => $dirtyHash], time());

        self::assertSame([], $lastRun->filterUnchanged(['a.txt'], $changedFiles));

        $repo->destroy();
    }

    public function test_filter_unchanged_keeps_a_file_edited_again_since_the_snapshot(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'original');
        $repo->commitAll('initial');

        $repo->write('a.txt', 'dirty content');

        $changedFiles = new ChangedFiles($repo->root);
        $dirtyHash = $changedFiles->currentHash('a.txt');
        self::assertNotNull($dirtyHash);

        $lastRun = new LastRunTree('main', $repo->sha(), ['a.txt' => $dirtyHash], time());

        // Edited again after the snapshot was taken.
        $repo->write('a.txt', 'dirty content v2');

        self::assertSame(['a.txt'], $lastRun->filterUnchanged(['a.txt'], $changedFiles));

        $repo->destroy();
    }

    public function test_filter_unchanged_keeps_a_file_reverted_to_its_pre_dirty_content(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'original');
        $repo->commitAll('initial');

        $repo->write('a.txt', 'dirty content');

        $changedFiles = new ChangedFiles($repo->root);
        $dirtyHash = $changedFiles->currentHash('a.txt');
        self::assertNotNull($dirtyHash);

        $lastRun = new LastRunTree('main', $repo->sha(), ['a.txt' => $dirtyHash], time());

        // Reverted back to the content that predates the dirty snapshot: the hash
        // differs from the snapshotted (dirty) hash, so the file must be re-tested.
        $repo->write('a.txt', 'original');

        self::assertSame(['a.txt'], $lastRun->filterUnchanged(['a.txt'], $changedFiles));

        $repo->destroy();
    }

    public function test_filter_unchanged_considers_snapshot_keys_not_in_candidates(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $changedFiles = new ChangedFiles($repo->root);

        // "gone.txt" is only present in the snapshot tree (never existed on disk
        // in this fresh repo), so it must join the candidate union and, since it
        // no longer exists, be kept.
        $lastRun = new LastRunTree('main', $repo->sha(), ['gone.txt' => 'some-hash'], time());

        $result = $lastRun->filterUnchanged(['a.txt'], $changedFiles);

        self::assertContains('gone.txt', $result);
        self::assertContains('a.txt', $result);

        $repo->destroy();
    }

    public function test_filter_unchanged_returns_candidates_unmodified_when_tree_is_empty(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $changedFiles = new ChangedFiles($repo->root);
        $lastRun = new LastRunTree('main', null, [], time());

        self::assertSame(['a.txt'], $lastRun->filterUnchanged(['a.txt'], $changedFiles));

        $repo->destroy();
    }
}
