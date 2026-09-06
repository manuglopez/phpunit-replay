<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Hermeticity;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = TempDir::make('policy');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);

        parent::tearDown();
    }

    private function policy(Graph $graph): Policy
    {
        return new Policy($graph, Config::defaults(), Quarantine::load($this->root . '/state'), $this->root);
    }

    public function test_everything_is_cacheable_when_the_graph_marks_nothing(): void
    {
        $policy = $this->policy(new Graph($this->root));

        self::assertTrue($policy->cacheable('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
        self::assertNull($policy->reason('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
    }

    public function test_a_file_marked_not_cacheable_is_not_cacheable(): void
    {
        $graph = new Graph($this->root);
        $graph->setNotCacheable(['tests/FooTest.php']);

        $policy = $this->policy($graph);

        self::assertFalse($policy->cacheable('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
        self::assertSame('attribute', $policy->reason('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
        self::assertTrue($policy->cacheable('tests/BarTest.php', 'App\Tests\BarTest::testBar'));
    }

    public function test_a_single_test_id_marked_not_cacheable_leaves_its_siblings_cacheable(): void
    {
        $graph = new Graph($this->root);
        $graph->setNotCacheable(['App\Tests\FooTest::testBar']);

        $policy = $this->policy($graph);

        self::assertFalse($policy->cacheable('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
        self::assertTrue($policy->cacheable('tests/FooTest.php', 'App\Tests\FooTest::testBaz'));
    }

    public function test_non_cacheable_files_lists_files_marked_directly_and_through_a_test_id(): void
    {
        $graph = new Graph($this->root);
        $graph->setNotCacheable(['tests/FooTest.php', 'App\Tests\BazTest::testOne']);

        $files = $this->policy($graph)->nonCacheableFiles(
            ['tests/FooTest.php', 'tests/BarTest.php', 'tests/BazTest.php'],
            [
                'tests/BarTest.php' => ['App\Tests\BarTest::testOne'],
                'tests/BazTest.php' => ['App\Tests\BazTest::testOne', 'App\Tests\BazTest::testTwo'],
            ],
        );

        self::assertSame(['tests/BazTest.php', 'tests/FooTest.php'], $files);
    }
}
