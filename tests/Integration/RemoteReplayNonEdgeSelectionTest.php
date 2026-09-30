<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * A remote object is addressed by the content key `k` (fingerprint + the test file's own
 * hash + the hashes of the files it executed, SPEC.md §9), so it can vouch only for the
 * things `k` covers. A test file selected because a WATCHED file changed is selected for a
 * reason `k` does not contain: the watched file is not one of its recorded dependencies, so
 * `k` is unchanged, the remote object still hits, and — before the fix — the test was
 * replayed as passed without running. Executed, it fails; that is what these tests pin.
 *
 * The scenario is the same on both paths that consult the remote: the `phpunit-replay`
 * wrapper (`Console\Runner\RunPipeline::replayFromRemote()`) and the in-process extension
 * (`PHPUnit\ReplayState::replayAffectedFromRemote()`).
 */
final class RemoteReplayNonEdgeSelectionTest extends TestCase
{
    private const ORIGIN = 'https://example.invalid/acme/shop.git';

    private FixtureProject $machine1;

    private ?FixtureProject $machine2 = null;

    private string $sharedCache;

    /** @var list<string> */
    private array $temporaries = [];

    protected function setUp(): void
    {
        $this->sharedCache = $this->tempDir('shared-cache');
    }

    protected function tearDown(): void
    {
        $this->machine1->destroy();
        $this->machine2?->destroy();

        foreach ($this->temporaries as $dir) {
            TempDir::remove($dir);
        }
    }

    public function test_a_watched_file_change_executes_the_test_instead_of_replaying_it_from_the_remote(): void
    {
        $this->machine1 = $this->withWatchedDataFile(FixtureProject::plain($this->tempDir('machine1') . '/shop'));

        $recorded = $this->machine1->replay(['record'], $this->env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->machine1->write('data/names.txt', "BROKEN\n");

        // Plain PHPUnit is the ground truth: the watched data file is what the test reads.
        self::assertNotSame(0, $this->machine1->phpunit()['exitCode']);

        $run = $this->machine1->replay([], $this->env());

        self::assertNotSame(0, $run['exitCode'], "a green run here is a false green:\n" . $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('testDataFile', $run['stdout'] . $run['stderr']);
    }

    public function test_the_control_without_a_remote_executes_the_watched_test(): void
    {
        $this->machine1 = $this->withWatchedDataFile(FixtureProject::plain($this->tempDir('machine1') . '/shop'));
        $env = ['PHPUNIT_REPLAY_REMOTE' => '', 'CI' => ''];

        self::assertSame(0, $this->machine1->replay(['record'], $env)['exitCode']);

        $this->machine1->write('data/names.txt', "BROKEN\n");
        $run = $this->machine1->replay([], $env);

        self::assertNotSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
    }

    public function test_a_key_covered_selection_is_still_served_from_the_remote_while_the_watched_test_executes(): void
    {
        $this->twoMachines();

        // Machine 2 makes a source change and runs it, publishing the objects for the files
        // that source reaches.
        $this->machine2->applyVariant('Money.behaviour.php', 'src/Money.php');
        $published = $this->machine2->replay([], $this->env());
        self::assertSame(0, $published['exitCode'], $published['stdout'] . $published['stderr']);

        // Machine 1 makes the same source change (a pure PhpEdge selection whose `k` is on
        // the remote) AND breaks the watched file (a Watch selection `k` knows nothing of).
        $this->machine1->applyVariant('Money.behaviour.php', 'src/Money.php');
        $this->machine1->write('data/names.txt', "BROKEN\n");

        $run = $this->machine1->replay([], $this->env());

        self::assertNotSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('testDataFile', $run['stdout'] . $run['stderr']);
        // The feature is intact for everything `k` does cover.
        self::assertMatchesRegularExpression('/\([1-9]\d* from remote\)/', ReplayAssert::lastLine($run['stdout']));
    }

    public function test_explain_shows_the_watch_selection_and_marks_the_files_served_from_the_remote(): void
    {
        $this->twoMachines();

        $this->machine2->applyVariant('Money.behaviour.php', 'src/Money.php');
        self::assertSame(0, $this->machine2->replay([], $this->env())['exitCode']);

        $this->machine1->applyVariant('Money.behaviour.php', 'src/Money.php');
        $this->machine1->write('data/names.txt', "BROKEN\n");

        $explain = $this->machine1->replay(['--explain', '--dry-run'], $this->env());
        $lines = explode("\n", $explain['stdout']);

        $greeter = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'tests/GreeterTest.php')));
        self::assertCount(1, $greeter, $explain['stdout']);
        self::assertStringContainsString('Watch', $greeter[0]);
        self::assertStringNotContainsString('remote', $greeter[0], 'the watched file must execute, not be served');

        $money = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'tests/MoneyTest.php')));
        self::assertCount(1, $money, 'a file served from the remote must not vanish from --explain: ' . $explain['stdout']);
        self::assertStringContainsString('PhpEdge', $money[0]);
        self::assertStringContainsString('served from remote', $money[0]);
    }

    public function test_in_process_a_watched_file_change_executes_the_test_instead_of_replaying_it_from_the_remote(): void
    {
        $this->machine1 = $this->withWatchedDataFile(FixtureProject::inprocess($this->tempDir('machine1') . '/project'));

        $recorded = $this->machine1->phpunitInProcess([], $this->env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $this->machine1->write('data/names.txt', "BROKEN\n");

        $run = $this->machine1->phpunitInProcess([], $this->env());

        self::assertNotSame(0, $run['exitCode'], "a green run here is a false green:\n" . $run['stdout'] . $run['stderr']);
        self::assertStringContainsString('testDataFile', $run['stdout'] . $run['stderr']);
    }

    private function twoMachines(): void
    {
        $this->machine1 = $this->withWatchedDataFile(FixtureProject::plain($this->tempDir('machine1') . '/shop'));
        $this->machine1->repo->git('remote', 'add', 'origin', self::ORIGIN);
        $this->machine2 = $this->machine1->copyTo($this->tempDir('machine2') . '/warehouse');

        $recorded = $this->machine1->replay(['record'], $this->env());
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
    }

    /**
     * `data/**` watched for GreeterTest, and a GreeterTest test reading `data/names.txt`
     * (which no PHP edge covers: it is read, not loaded), committed as the baseline.
     */
    private function withWatchedDataFile(FixtureProject $project): FixtureProject
    {
        $project->write('data/names.txt', "Ana\n");
        $project->write('phpunit-replay.php', "<?php\nreturn ['watch' => ['data/**' => 'tests/GreeterTest.php']];\n");
        $project->write('tests/GreeterTest.php', str_replace(
            'public function testBlankNameFallsBackToStranger',
            "public function testDataFile(): void\n    {\n"
            . "        self::assertSame('Ana', trim((string) file_get_contents(__DIR__ . '/../data/names.txt')));\n    }\n\n"
            . '    public function testBlankNameFallsBackToStranger',
            $project->read('tests/GreeterTest.php'),
        ));
        $project->repo->commitAll('watch a data file');

        return $project;
    }

    /** @return array<string, string> */
    private function env(): array
    {
        return [
            'PHPUNIT_REPLAY_REMOTE' => 'file://' . $this->sharedCache,
            'PHPUNIT_REPLAY_REMOTE_PUSH' => 'all',
            // The package's own suite may itself run under CI, where a pass refuses to
            // publish a baseline; these fixtures are testing the developer path.
            'CI' => '',
        ];
    }

    private function tempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->temporaries[] = $dir;

        return $dir;
    }
}
