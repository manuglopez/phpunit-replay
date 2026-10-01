<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Select\Reason;
use Manuglopez\Replay\Select\StampAudit;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * `Select\StampAudit` with the current tree reduced to two lookup tables: the content key and
 * the non-edge digest each test file has "now".
 */
final class StampAuditTest extends TestCase
{
    private string $root;

    /** @var array<string, ?string> */
    private array $keys = ['tests/ATest.php' => 'kA', 'tests/BTest.php' => 'kB'];

    /** @var array<string, ?string> */
    private array $digests = ['tests/ATest.php' => 'n1:a', 'tests/BTest.php' => 'n1:b'];

    protected function setUp(): void
    {
        $this->root = TempDir::make('stamp-audit');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    public function test_results_stamped_with_the_current_key_and_digest_are_served(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'ATest::a', self::stamped('tests/ATest.php', 'kA', 'n1:a', 'main'));
        $graph->setResult('main', 'BTest::b', self::stamped('tests/BTest.php', 'kB', 'n1:b', 'main'));

        self::assertSame([], $this->audit($graph)->apply('main'));
        self::assertCount(2, $graph->results('main'));
    }

    public function test_each_kind_of_mismatch_is_forgotten_from_the_own_layer_with_its_reason(): void
    {
        $graph = $this->graph();
        $graph->setRecordedSha('main', str_repeat('a', 40));
        $graph->setResult('main', 'ATest::a', self::stamped('tests/ATest.php', 'k-old', 'n1:a', 'main'));
        $graph->setResult('main', 'BTest::b', self::stamped('tests/BTest.php', 'kB', 'n1:other', 'main'));
        $graph->setResult('main', 'CTest::c', ['status' => 0, 'message' => '', 'time' => 0.0, 'assertions' => 1, 'file' => 'tests/CTest.php', 'key' => 'kC']);
        $this->keys['tests/CTest.php'] = 'kC';
        $this->digests['tests/CTest.php'] = 'n1:c';

        $stale = $this->audit($graph)->apply('main');

        self::assertEquals(new Reason('StaleResult', 'content key changed', 'main@aaaaaaa'), $stale['tests/ATest.php']['reason']);
        self::assertEquals(new Reason('StaleResult', 'non-edge inputs changed', 'main@aaaaaaa'), $stale['tests/BTest.php']['reason']);
        self::assertEquals(new Reason('StaleResult', 'unstamped', 'main@aaaaaaa'), $stale['tests/CTest.php']['reason']);
        self::assertSame(['CTest::c'], $stale['tests/CTest.php']['ids']);
        self::assertSame([], $graph->results('main'), 'nothing invalid is served');
        self::assertCount(3, $graph->ownResults('main'), 'and nothing is deleted: a later pass re-checks it');
    }

    public function test_an_invalid_result_of_another_branch_is_withheld_and_the_next_valid_layer_serves(): void
    {
        $graph = $this->graph();
        $graph->setNearestBranch('develop');
        // develop's result was recorded on other content; main's is valid.
        $graph->setResult('develop', 'ATest::a', self::stamped('tests/ATest.php', 'k-old', 'n1:a', 'develop'));
        $graph->setResult('main', 'ATest::a', self::stamped('tests/ATest.php', 'kA', 'n1:a', 'main'));

        $stale = $this->audit($graph)->apply('feature');

        self::assertSame(['tests/ATest.php'], array_keys($stale));
        self::assertEquals(new Reason('StaleResult', 'content key changed', 'develop'), $stale['tests/ATest.php']['reason']);
        self::assertSame('main', $graph->results('feature')['ATest::a']['message']);
        self::assertArrayHasKey('ATest::a', $graph->ownResults('develop'), 'another branch\'s layer is only masked');
    }

    public function test_results_are_masked_by_id_and_a_fresh_one_is_served_again(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'ATest::a', self::stamped('tests/ATest.php', 'kA', 'n1:a', 'main'));
        $graph->setResult('main', 'ATest::b', self::stamped('tests/ATest.php', 'kA', null, 'main'));

        $stale = $this->audit($graph)->apply('main');

        // The file is stale because of b; a stays servable (the stale bucket still runs the
        // file, since b has nothing left to serve it).
        self::assertSame(['ATest::b'], $stale['tests/ATest.php']['ids']);
        self::assertSame(['ATest::a'], array_keys($graph->results('main')));

        $graph->setResult('main', 'ATest::b', self::stamped('tests/ATest.php', 'kA', 'n1:a', 'fresh'));
        self::assertSame('fresh', $graph->results('main')['ATest::b']['message'] ?? null);
    }

    public function test_a_masked_result_left_unreplaced_fails_again_on_the_next_pass(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'ATest::gone', self::stamped('tests/ATest.php', 'k-old', 'n1:a', 'main'));
        $this->audit($graph)->apply('main');

        $next = Graph::decode((string) $graph->encode(), $this->root);
        self::assertNotNull($next);
        $next->setDefaultBranch('main');

        self::assertSame(['ATest::gone'], $this->audit($next)->apply('main')['tests/ATest.php']['ids']);
    }

    public function test_a_file_the_layer_audit_already_dropped_keeps_its_reason(): void
    {
        $graph = $this->graph();
        $graph->setNearestBranch('develop');
        $graph->setResult('develop', 'ATest::a', self::stamped('tests/ATest.php', 'k-old', 'n1:a', 'develop'));
        $earlier = new Reason('StaleLayer', 'src/A.php', 'feature@1234567');

        $stale = $this->audit($graph)->apply('feature', ['tests/ATest.php' => ['reason' => $earlier, 'ids' => ['ATest::x']]]);

        self::assertSame($earlier, $stale['tests/ATest.php']['reason']);
        self::assertSame(['ATest::x', 'ATest::a'], $stale['tests/ATest.php']['ids']);
    }

    public function test_a_digest_that_cannot_be_computed_validates_nothing(): void
    {
        $graph = $this->graph();
        $graph->setResult('main', 'ATest::a', self::stamped('tests/ATest.php', 'kA', 'n1:a', 'main'));
        $this->digests['tests/ATest.php'] = null;

        self::assertSame(['tests/ATest.php'], array_keys($this->audit($graph)->apply('main')));
    }

    private function audit(Graph $graph): StampAudit
    {
        return new StampAudit(
            $graph,
            fn (string $file): ?string => $this->keys[$file] ?? null,
            fn (string $file): ?string => $this->digests[$file] ?? null,
        );
    }

    private function graph(): Graph
    {
        $graph = new Graph($this->root);
        $graph->setDefaultBranch('main');

        return $graph;
    }

    /** @return array{status: int, message: string, time: float, assertions: int, file: string, key: string, digest?: string} */
    private static function stamped(string $file, string $key, ?string $digest, string $message): array
    {
        $result = ['status' => 0, 'message' => $message, 'time' => 0.0, 'assertions' => 1, 'file' => $file, 'key' => $key];

        if ($digest !== null) {
            $result['digest'] = $digest;
        }

        return $result;
    }
}
