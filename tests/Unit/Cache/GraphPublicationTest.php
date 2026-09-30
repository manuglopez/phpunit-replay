<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\GraphPublication;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Select\NonEdgeInputs;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\TestCase;

/** A graph is published only from a clean working tree (`Cache\GraphPublication`). */
final class GraphPublicationTest extends TestCase
{
    private GitRepo $repo;

    private Graph $graph;

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
        $this->repo->write('src/A.php', "<?php\n");
        $this->repo->write('tests/ATest.php', "<?php\n");
        $this->repo->write('tests/Fixtures/data.txt', "one\n");
        $this->repo->commitAll('initial');

        $this->graph = new Graph($this->repo->root);
        $this->graph->unionEdges(['tests/ATest.php' => ['src/A.php']]);
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    public function test_a_clean_tree_publishes(): void
    {
        self::assertSame([], $this->dirty());
        self::assertNull(GraphPublication::refusal(new Git($this->repo->root), $this->inputs(), 'the main baseline'));
    }

    public function test_any_tracked_modification_makes_it_dirty(): void
    {
        $this->repo->write('src/A.php', "<?php\n// edited\n");
        $this->repo->delete('tests/Fixtures/data.txt');

        self::assertSame(['src/A.php', 'tests/Fixtures/data.txt'], $this->dirty());
        self::assertStringContainsString(
            'the working tree is dirty (src/A.php, tests/Fixtures/data.txt)',
            (string) GraphPublication::refusal(new Git($this->repo->root), $this->inputs(), 'the main baseline'),
        );
    }

    public function test_an_untracked_file_counts_only_when_a_result_could_depend_on_it(): void
    {
        $this->repo->write('notes.txt', "nobody reads this\n");
        $this->repo->write('.phpunit-replay.xml', '<phpunit/>');
        self::assertSame([], $this->dirty(), 'a note and the generated configuration leave it clean');

        $this->repo->write('tests/Fixtures/new.txt', "a watched file\n");
        $this->repo->write('tests/NewTest.php', "<?php\n");
        self::assertSame(['tests/Fixtures/new.txt', 'tests/NewTest.php'], $this->dirty());
    }

    /** @return list<string>|null */
    private function dirty(): ?array
    {
        return GraphPublication::dirtyPaths(new Git($this->repo->root), $this->inputs());
    }

    private function inputs(): NonEdgeInputs
    {
        $watch = new WatchPatterns();
        $watch->add(['tests/**/Fixtures/**' => ['tests']]);

        return new NonEdgeInputs(
            $this->graph,
            new TestPaths(['tests'], [], ['Test.php']),
            $watch,
            $this->repo->root,
            new FileHashes($this->repo->root),
            new Git($this->repo->root),
        );
    }
}
