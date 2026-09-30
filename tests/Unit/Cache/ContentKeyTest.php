<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\ContentKey;
use Manuglopez\Replay\Cache\FileHashes;
use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ContentKeyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('contentkey');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    /**
     * @param  array<string, mixed>  $environmental
     * @return array<string, mixed>
     */
    private function fingerprint(string $schemaHash = 'abc', array $environmental = ['php' => '8.4']): array
    {
        return [
            'structural' => ['schema' => 1, 'composer_lock' => $schemaHash],
            'environmental' => $environmental,
        ];
    }

    /** @param array<string, mixed> $environmental */
    private function keyUnder(array $environmental): ?string
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo {}\n");

        return (new ContentKey($this->root))->compute(
            $this->fingerprint('abc', $environmental),
            'tests/Feature/FooTest.php',
            ['app/Foo.php'],
        );
    }

    public function test_same_content_produces_the_same_key(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo {}\n");

        $key = new ContentKey($this->root);

        $a = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);
        $b = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        self::assertNotNull($a);
        self::assertSame($a, $b);
    }

    public function test_comment_only_change_in_a_dependency_keeps_the_same_key(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo {}\n");

        $key = new ContentKey($this->root);
        $before = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        TempDir::write(
            $this->root . '/app/Foo.php',
            "<?php\n// A totally unrelated comment explaining Foo.\nclass Foo {}\n",
        );

        $after = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        self::assertNotNull($before);
        self::assertSame($before, $after);
    }

    public function test_code_change_in_a_dependency_produces_a_different_key(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo {}\n");

        $key = new ContentKey($this->root);
        $before = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo { public function bar() {} }\n");

        $after = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }

    public function test_deleted_dependency_produces_a_different_key(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        $depPath = $this->root . '/app/Foo.php';
        TempDir::write($depPath, "<?php\nclass Foo {}\n");

        $key = new ContentKey($this->root);
        $before = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        unlink($depPath);

        $after = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }

    public function test_fingerprint_change_produces_a_different_key(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo {}\n");

        $key = new ContentKey($this->root);

        $before = $key->compute($this->fingerprint('abc'), 'tests/Feature/FooTest.php', ['app/Foo.php']);
        $after = $key->compute($this->fingerprint('def'), 'tests/Feature/FooTest.php', ['app/Foo.php']);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }

    public function test_unreadable_test_file_yields_null(): void
    {
        $key = new ContentKey($this->root);

        $result = $key->compute($this->fingerprint(), 'tests/Feature/DoesNotExistTest.php', []);

        self::assertNull($result);
    }

    public function test_for_test_file_uses_the_graphs_fingerprint_and_dependencies(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo {}\n");

        $graph = new Graph($this->root);
        $graph->setFingerprint($this->fingerprint());
        $graph->link('tests/Feature/FooTest.php', 'app/Foo.php');

        $key = new ContentKey($this->root);

        $expected = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/Foo.php']);
        $actual = $key->forTestFile($graph, 'tests/Feature/FooTest.php');

        self::assertNotNull($expected);
        self::assertSame($expected, $actual);
    }

    public function test_a_different_php_minor_produces_a_different_key(): void
    {
        $before = $this->keyUnder(['php' => '8.3', 'os' => 'Linux']);
        $after = $this->keyUnder(['php' => '8.4', 'os' => 'Linux']);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }

    public function test_a_different_os_produces_a_different_key(): void
    {
        $before = $this->keyUnder(['php' => '8.4', 'os' => 'Linux']);
        $after = $this->keyUnder(['php' => '8.4', 'os' => 'Darwin']);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertNotSame($before, $after);
    }

    /**
     * A coverage driver decides which lines are *reported*, not whether an assertion passed,
     * and this package's own composer scripts alternate pcov and xdebug — a project doing the
     * same must not lose half its hits to it
     * ({@see \Manuglopez\Replay\Cache\Fingerprint::canonicalResultEnvironment()}).
     */
    public function test_a_different_coverage_driver_produces_the_same_key(): void
    {
        $pcov = $this->keyUnder(['php' => '8.4', 'os' => 'Linux', 'driver' => 'pcov']);
        $xdebug = $this->keyUnder(['php' => '8.4', 'os' => 'Linux', 'driver' => 'xdebug']);

        self::assertNotNull($pcov);
        self::assertSame($pcov, $xdebug);
    }

    /**
     * The coverage format guards the LOCAL snapshot store, which the address knows nothing
     * about: a result adopted from a remote carries no snapshot at all, and one this
     * installation cannot read is reported by `Report\CoverageMerger` rather than trusted.
     * Clearing the results whose snapshots went unreadable is the whole of that key's job,
     * and it does it from the environmental bucket.
     */
    public function test_a_different_coverage_format_produces_the_same_key(): void
    {
        $before = $this->keyUnder(['php' => '8.4', 'os' => 'Linux', 'coverage' => 'cc12/legacy/snap1']);
        $after = $this->keyUnder(['php' => '8.4', 'os' => 'Linux', 'coverage' => 'cc14/serializer/2']);

        self::assertNotNull($before);
        self::assertSame($before, $after);
    }

    /**
     * A graph.json written before the environment was part of the address, read back by this
     * version: the bucket may be missing altogether, or hold only some of the keys. Neither
     * may throw, and each addresses a set of its own — an old result must not be taken for
     * one recorded in this environment.
     */
    public function test_a_fingerprint_missing_the_environmental_bucket_still_produces_a_key(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/Foo.php', "<?php\nclass Foo {}\n");

        $bucketless = ['structural' => ['schema' => 1, 'composer_lock' => 'abc']];
        $key = new ContentKey($this->root);

        $withoutBucket = $key->compute($bucketless, 'tests/Feature/FooTest.php', ['app/Foo.php']);

        self::assertNotNull($withoutBucket);
        self::assertSame(
            $withoutBucket,
            $key->compute($bucketless, 'tests/Feature/FooTest.php', ['app/Foo.php']),
        );
        self::assertSame($withoutBucket, $this->keyUnder([]));
        self::assertNotSame($withoutBucket, $this->keyUnder(['php' => '8.4', 'os' => 'Linux']));
    }

    public function test_dependency_order_does_not_affect_the_key(): void
    {
        TempDir::write($this->root . '/tests/Feature/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/A.php', "<?php\nclass A {}\n");
        TempDir::write($this->root . '/app/B.php', "<?php\nclass B {}\n");

        $key = new ContentKey($this->root);

        $a = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/A.php', 'app/B.php']);
        $b = $key->compute($this->fingerprint(), 'tests/Feature/FooTest.php', ['app/B.php', 'app/A.php']);

        self::assertNotNull($a);
        self::assertSame($a, $b);
    }

    public function test_an_object_holds_only_the_results_recorded_under_its_own_key_for_its_own_file(): void
    {
        $results = [
            'FooTest::now' => ['status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1, 'file' => 'tests/FooTest.php', 'key' => 'k1', 'digest' => 'n1:d'],
            'FooTest::renamedAway' => ['status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1, 'file' => 'tests/FooTest.php', 'key' => 'k0', 'digest' => 'n1:d'],
            'FooTest::unstamped' => ['status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1, 'file' => 'tests/FooTest.php'],
            'FooTest::keyOnly' => ['status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1, 'file' => 'tests/FooTest.php', 'key' => 'k1'],
            'FooTest::otherInputs' => ['status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1, 'file' => 'tests/FooTest.php', 'key' => 'k1', 'digest' => 'n1:e'],
            'BarTest::other' => ['status' => 0, 'message' => '', 'time' => 0.1, 'assertions' => 1, 'file' => 'tests/BarTest.php', 'key' => 'k1', 'digest' => 'n1:d'],
        ];

        self::assertSame(['FooTest::now'], array_keys(ContentKey::resultsRecordedAt($results, 'tests/FooTest.php', 'k1', 'n1:d')));
        self::assertSame([], ContentKey::resultsRecordedAt($results, 'tests/FooTest.php', 'k2', 'n1:d'));
    }

    public function test_a_shared_hash_memo_reads_each_file_once_and_gives_the_same_key(): void
    {
        TempDir::write($this->root . '/tests/FooTest.php', "<?php\nclass FooTest {}\n");
        TempDir::write($this->root . '/app/A.php', "<?php\nclass A {}\n");

        $hashes = new FileHashes($this->root);
        $memoised = new ContentKey($this->root, $hashes);
        $plain = new ContentKey($this->root);

        $first = $memoised->compute($this->fingerprint(), 'tests/FooTest.php', ['app/A.php']);
        self::assertSame($plain->compute($this->fingerprint(), 'tests/FooTest.php', ['app/A.php']), $first);

        // Within one pass a file is read once: the stamp describes one moment of the tree.
        TempDir::write($this->root . '/app/A.php', "<?php\nclass A { public int \$x = 1; }\n");
        self::assertSame($first, $memoised->compute($this->fingerprint(), 'tests/FooTest.php', ['app/A.php']));
        self::assertNotSame($first, $plain->compute($this->fingerprint(), 'tests/FooTest.php', ['app/A.php']));
    }
}
