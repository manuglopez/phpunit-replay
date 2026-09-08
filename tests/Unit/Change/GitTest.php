<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Change;

use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class GitTest extends TestCase
{
    public function test_available_is_true_when_git_binary_is_present(): void
    {
        self::assertTrue(Git::available());
    }

    public function test_is_repository_is_true_inside_a_real_repository(): void
    {
        $repo = GitRepo::init();

        self::assertTrue((new Git($repo->root))->isRepository());

        $repo->destroy();
    }

    public function test_is_repository_is_false_outside_any_repository(): void
    {
        $dir = TempDir::make('non-repo');

        self::assertFalse((new Git($dir))->isRepository());

        TempDir::remove($dir);
    }

    public function test_has_commits_is_false_before_the_first_commit(): void
    {
        $repo = GitRepo::init();

        self::assertFalse((new Git($repo->root))->hasCommits());

        $repo->destroy();
    }

    public function test_has_commits_is_true_after_a_commit(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        self::assertTrue((new Git($repo->root))->hasCommits());

        $repo->destroy();
    }

    public function test_current_branch_and_sha(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');

        $git = new Git($repo->root);

        self::assertSame('main', $git->currentBranch());
        self::assertSame($sha, $git->currentSha());

        $repo->destroy();
    }

    public function test_current_branch_is_null_on_detached_head(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha = $repo->commitAll('initial');
        $repo->git('checkout', '-q', $sha);

        self::assertNull((new Git($repo->root))->currentBranch());

        $repo->destroy();
    }

    public function test_default_branch_is_main_when_only_main_exists(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        self::assertSame('main', (new Git($repo->root))->defaultBranch());

        $repo->destroy();
    }

    public function test_branch_names_includes_created_branches(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');
        $repo->checkout('feature', create: true);

        $names = (new Git($repo->root))->branchNames();

        self::assertContains('main', $names);
        self::assertContains('feature', $names);

        $repo->destroy();
    }

    public function test_is_ancestor_is_true_for_a_reachable_sha(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $sha1 = $repo->commitAll('first');
        $repo->write('a.txt', 'b');
        $repo->commitAll('second');

        self::assertTrue((new Git($repo->root))->isAncestor($sha1));

        $repo->destroy();
    }

    public function test_is_ancestor_is_false_for_a_bogus_sha(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $bogus = str_repeat('a', 40);

        self::assertFalse((new Git($repo->root))->isAncestor($bogus));

        $repo->destroy();
    }

    public function test_is_ancestor_is_false_for_an_unrelated_orphan_commit(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $repo->git('checkout', '-q', '--orphan', 'unrelated');
        $repo->git('rm', '-rf', '--cached', '.');
        $repo->write('b.txt', 'b');
        $orphanSha = $repo->commitAll('unrelated root');

        $repo->git('checkout', '-q', 'main');

        self::assertFalse((new Git($repo->root))->isAncestor($orphanSha));

        $repo->destroy();
    }

    public function test_show_reads_file_content_at_a_sha(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'hello');
        $sha = $repo->commitAll('initial');

        self::assertSame('hello', (new Git($repo->root))->show($sha, 'a.txt'));

        $repo->destroy();
    }

    public function test_has_remote_is_false_then_true(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        $git = new Git($repo->root);

        self::assertFalse($git->hasRemote());

        $repo->git('remote', 'add', 'origin', $repo->root);

        self::assertTrue($git->hasRemote());

        $repo->destroy();
    }

    public function test_top_level_returns_the_repository_root(): void
    {
        $repo = GitRepo::init();
        $repo->write('a.txt', 'a');
        $repo->commitAll('initial');

        self::assertSame(realpath($repo->root), (new Git($repo->root))->topLevel());

        $repo->destroy();
    }

    public function test_result_reports_exit_code_127_when_the_process_cannot_be_started(): void
    {
        // A non-existent working directory makes Symfony\Process throw a RuntimeException
        // when starting, the same defensive path used for a missing git binary.
        $broken = new Git('/definitely/does/not/exist/'.bin2hex(random_bytes(4)));

        $result = $broken->result(['--version']);

        self::assertSame(127, $result['exitCode']);
        self::assertSame('', $result['output']);
    }

    // -- ignored() — the shared check-ignore helper (Change\ChangedFiles and
    // Cache\GraphUpdater's edge filter both call this rather than shelling out themselves) --

    public function test_ignored_returns_an_empty_array_without_running_git_for_an_empty_list(): void
    {
        // A non-existent directory would make any real git invocation fail (exit 127); an
        // empty result here instead proves the empty-input short circuit never shells out.
        $git = new Git('/definitely/does/not/exist/'.bin2hex(random_bytes(4)));

        self::assertSame([], $git->ignored([]));
    }

    public function test_ignored_reports_a_path_matched_by_gitignore(): void
    {
        $repo = GitRepo::init();
        $repo->write('.gitignore', "/bootstrap/cache/\n");
        $repo->write('bootstrap/cache/views/x.php', '<?php');
        $repo->write('src/Kept.php', '<?php');
        $repo->commitAll('initial');

        $ignored = (new Git($repo->root))->ignored(['bootstrap/cache/views/x.php', 'src/Kept.php']);

        self::assertSame(['bootstrap/cache/views/x.php' => true], $ignored);

        $repo->destroy();
    }

    public function test_ignored_reports_nothing_when_no_path_matches(): void
    {
        $repo = GitRepo::init();
        $repo->write('src/Kept.php', '<?php');
        $repo->commitAll('initial');

        self::assertSame([], (new Git($repo->root))->ignored(['src/Kept.php']));

        $repo->destroy();
    }

    /**
     * `--no-index` means a path already tracked in the index is still reported as ignored
     * when it also matches a `.gitignore` pattern (the default `check-ignore` behaviour
     * suppresses a tracked file; `--no-index` is specifically what turns that off) — the
     * batched helper does not accidentally lose this by adding its own tracked-ness check.
     */
    public function test_ignored_reports_a_tracked_file_that_also_matches_gitignore(): void
    {
        $repo = GitRepo::init();
        $repo->write('debug.log', 'noise');
        $repo->commitAll('initial (before the ignore rule existed)');
        $repo->write('.gitignore', "debug.log\n");
        $repo->commitAll('add gitignore');

        self::assertSame(['debug.log' => true], (new Git($repo->root))->ignored(['debug.log']));

        $repo->destroy();
    }

    public function test_ignored_is_null_outside_any_repository(): void
    {
        $dir = TempDir::make('non-repo-ignored');

        self::assertNull((new Git($dir))->ignored(['whatever.php']));

        TempDir::remove($dir);
    }

    public function test_ignored_is_null_when_the_process_cannot_be_started(): void
    {
        $broken = new Git('/definitely/does/not/exist/'.bin2hex(random_bytes(4)));

        self::assertNull($broken->ignored(['whatever.php']));
    }
}
