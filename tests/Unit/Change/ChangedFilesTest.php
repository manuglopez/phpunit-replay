<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Change;

use Manuglopez\Replay\Change\ChangedFiles;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ChangedFilesTest extends TestCase
{
    public function test_since_is_empty_right_after_a_clean_commit(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');

        $result = (new ChangedFiles($repo->root))->since($sha);

        self::assertSame([], $result);

        $repo->destroy();
    }

    public function test_modified_tracked_file_is_listed(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');

        $repo->write('a.txt', 'b');

        self::assertSame(['a.txt'], (new ChangedFiles($repo->root))->since($sha));

        $repo->destroy();
    }

    public function test_comment_only_php_modification_is_not_listed(): void
    {
        $repo = GitRepo::init();
        $repo->write('Foo.php', "<?php\n\nfunction foo() {\n    return 1;\n}\n");
        $sha = $repo->commitAll('initial');

        $repo->write('Foo.php', "<?php\n\n// a harmless comment\nfunction foo() {\n    return 1;\n}\n");

        self::assertSame([], (new ChangedFiles($repo->root))->since($sha));

        $repo->destroy();
    }

    public function test_reverting_to_the_baseline_content_is_not_listed(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'original');
        $sha = $repo->commitAll('baseline');

        $repo->write('a.txt', 'changed');
        $repo->commitAll('second commit');

        // Working tree reverts the file back to exactly the baseline sha's content.
        $repo->write('a.txt', 'original');

        self::assertSame([], (new ChangedFiles($repo->root))->since($sha));

        $repo->destroy();
    }

    public function test_deleted_tracked_file_is_listed(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');

        $repo->delete('a.txt');

        self::assertSame(['a.txt'], (new ChangedFiles($repo->root))->since($sha));

        $repo->destroy();
    }

    public function test_renamed_tracked_file_lists_both_old_and_new_paths(): void
    {
        $repo = GitRepo::init();
        $repo->write('old.txt', 'a');
        $sha = $repo->commitAll('initial');

        $repo->git('mv', 'old.txt', 'new.txt');

        $result = (new ChangedFiles($repo->root))->since($sha);

        self::assertSame(['new.txt', 'old.txt'], $result);

        $repo->destroy();
    }

    public function test_untracked_new_file_is_listed(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');

        $repo->write('new.txt', 'new');

        self::assertSame(['new.txt'], (new ChangedFiles($repo->root))->since($sha));

        $repo->destroy();
    }

    public function test_ignored_file_is_not_listed(): void
    {
        $repo = GitRepo::init();
        $repo->write('.gitignore', "ignored.txt\n");
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');

        $repo->write('ignored.txt', 'secret');

        self::assertSame([], (new ChangedFiles($repo->root))->since($sha));

        $repo->destroy();
    }

    public function test_committed_change_after_baseline_sha_is_listed(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');

        $repo->write('a.txt', 'b');
        $repo->commitAll('second');

        self::assertSame(['a.txt'], (new ChangedFiles($repo->root))->since($sha));

        $repo->destroy();
    }

    public function test_since_is_null_when_sha_is_not_an_ancestor(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $bogus = str_repeat('a', 40);

        self::assertNull((new ChangedFiles($repo->root))->since($bogus));

        $repo->destroy();
    }

    public function test_since_is_null_outside_a_repository(): void
    {
        $dir = TempDir::make('non-repo');

        self::assertNull((new ChangedFiles($dir))->since(null));

        TempDir::remove($dir);
    }

    public function test_since_without_a_sha_still_lists_dirty_and_untracked_files(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $repo->write('a.txt', 'b');
        $repo->write('untracked.txt', 'u');

        $result = (new ChangedFiles($repo->root))->since(null);

        self::assertSame(['a.txt', 'untracked.txt'], $result);

        $repo->destroy();
    }

    public function test_snapshot_tree_and_current_hash(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $changedFiles = new ChangedFiles($repo->root);

        $snapshot = $changedFiles->snapshotTree(['a.txt', 'missing.txt']);

        self::assertSame($changedFiles->currentHash('a.txt'), $snapshot['a.txt']);
        self::assertSame('', $snapshot['missing.txt']);
        self::assertNull($changedFiles->currentHash('missing.txt'));

        $repo->destroy();
    }
}
