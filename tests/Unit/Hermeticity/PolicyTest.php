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

    public function test_a_never_cache_glob_makes_a_matching_file_not_cacheable(): void
    {
        $config = Config::fromArray(['never_cache' => ['tests/Feature/External/**']]);
        $policy = new Policy(new Graph($this->root), $config, Quarantine::load($this->root . '/state'), $this->root);

        self::assertFalse($policy->cacheable('tests/Feature/External/PaymentTest.php', 'App\Tests\PaymentTest::testPay'));
        self::assertSame('never_cache', $policy->reason('tests/Feature/External/PaymentTest.php', 'App\Tests\PaymentTest::testPay'));
        self::assertTrue($policy->cacheable('tests/Feature/PaymentTest.php', 'App\Tests\PaymentTest::testPay'));
    }

    public function test_a_never_cache_glob_does_not_affect_a_non_matching_file(): void
    {
        $config = Config::fromArray(['never_cache' => ['tests/Browser/**']]);
        $policy = new Policy(new Graph($this->root), $config, Quarantine::load($this->root . '/state'), $this->root);

        self::assertTrue($policy->cacheable('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
        self::assertNull($policy->reason('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
    }

    public function test_a_quarantined_test_id_is_not_cacheable(): void
    {
        $quarantine = new Quarantine();
        $quarantine->recordFlip('App\Tests\FooTest::testBar', 'some-key');

        $policy = new Policy(new Graph($this->root), Config::defaults(), $quarantine, $this->root);

        self::assertFalse($policy->cacheable('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
        self::assertSame('quarantine', $policy->reason('tests/FooTest.php', 'App\Tests\FooTest::testBar'));
        self::assertTrue($policy->cacheable('tests/FooTest.php', 'App\Tests\FooTest::testBaz'));
    }

    public function test_reason_prefers_attribute_then_never_cache_then_quarantine(): void
    {
        $graph = new Graph($this->root);
        $graph->setNotCacheable(['App\Tests\FooTest::testBar']);

        $config = Config::fromArray(['never_cache' => ['tests/**']]);

        $quarantine = new Quarantine();
        $quarantine->recordFlip('App\Tests\FooTest::testBar', 'some-key');
        $quarantine->recordFlip('App\Tests\FooTest::testBaz', 'some-key');

        $policy = new Policy($graph, $config, $quarantine, $this->root);

        // All three rules would fire for this id; the attribute wins.
        self::assertSame('attribute', $policy->reason('tests/FooTest.php', 'App\Tests\FooTest::testBar'));

        // Only the glob and the quarantine apply to this sibling: the glob wins.
        self::assertSame('never_cache', $policy->reason('tests/FooTest.php', 'App\Tests\FooTest::testBaz'));
    }
}
