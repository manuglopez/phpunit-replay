<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Integration;

use Manuglopez\Replay\Tests\Support\FixtureProject;
use Manuglopez\Replay\Tests\Support\ReplayAssert;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * What a stamp decides beyond serving a local result: what the remote may serve, what may be
 * published, and which changes select which tests (F5: files coverage cannot see; F6: watch
 * patterns over files with edges).
 */
final class StampsAndPublishingTest extends TestCase
{
    /** @var list<FixtureProject> */
    private array $fixtures = [];

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $fixture) {
            $fixture->destroy();
        }

        foreach ($this->dirs as $dir) {
            TempDir::remove($dir);
        }
    }

    public function test_in_process_a_result_served_from_an_object_without_a_digest_is_not_stamped_as_validated(): void
    {
        // A replayed result used to be re-stamped with this pass's key and digest when the
        // in-process path persisted it: one served from an object written before digests
        // came out of it looking validated, and was served again from then on.
        $shared = $this->dir('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'objects', 'CI' => ''];
        [$m1, $m2] = $this->twoMachines(FixtureProject::inprocess(TempDir::make('m1') . '/shop'));

        self::assertSame(0, $m1->phpunitInProcess([], $env)['exitCode']);
        self::assertSame(0, $m2->phpunitInProcess([], $env)['exitCode']);

        $m1->write('src/Greeter.php', str_replace('$trimmed = trim($name);', '$trimmed = trim($name, " \t\n\r\0\x0B");', $m1->read('src/Greeter.php')));
        $m1->repo->commitAll('Greeter changes, behaviour kept');
        self::assertSame(0, $m1->phpunitInProcess([], $env)['exitCode']);
        self::withoutDigests($shared);

        $m2->repo->git('fetch', '-q', $m1->root(), 'main');
        $m2->repo->git('reset', '-q', '--hard', 'FETCH_HEAD');
        $served = $m2->phpunitInProcess([], $env);
        self::assertSame(0, $served['exitCode'], $served['stdout'] . $served['stderr']);
        self::assertMatchesRegularExpression('/\([1-9]\d* from remote\)/', $served['stdout'], 'precondition: the legacy object served the key-covered file');

        $graph = ReplayAssert::loadGraph($m2);
        self::assertNotNull($graph);
        $greeter = array_filter($graph->ownResults('main'), static fn (array $r): bool => ($r['file'] ?? '') === 'tests/GreeterTest.php');
        self::assertNotSame([], $greeter);

        foreach ($greeter as $testId => $result) {
            self::assertArrayNotHasKey('digest', $result, $testId . ' was re-stamped although this pass did not run it');
        }

        $again = $m2->phpunitInProcess([], $env);
        self::assertGreaterThan(0, ReplayAssert::executedCount($again['stdout']), 'it runs once, here, and is stamped then');
    }

    public function test_a_changed_file_source_exclude_keeps_out_of_coverage_runs_every_test(): void
    {
        // F5: coverage never reports such a file, so it has no edge and selected nothing.
        $fixture = $this->plain();
        $fixture->write('phpunit.xml', str_replace(
            "        </include>\n    </source>",
            "        </include>\n        <exclude>\n            <directory>src/Excluded</directory>\n        </exclude>\n    </source>",
            $fixture->read('phpunit.xml'),
        ));
        $fixture->write('src/Excluded/Rate.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Excluded;\n\nfinal class Rate\n{\n    public const VALUE = 21;\n}\n");
        $fixture->write('tests/RateTest.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Tests;\n\nuse App\\Excluded\\Rate;\nuse PHPUnit\\Framework\\TestCase;\n\nfinal class RateTest extends TestCase\n{\n    public function testTheRate(): void\n    {\n        self::assertSame(21, Rate::VALUE);\n    }\n}\n");
        $fixture->repo->commitAll('a class outside coverage');
        self::assertSame(0, $fixture->replay(['record'], self::env())['exitCode']);

        $fixture->write('src/Excluded/Rate.php', str_replace('VALUE = 21', 'VALUE = 22', $fixture->read('src/Excluded/Rate.php')));
        $fixture->repo->commitAll('the rate changes');
        self::assertSame(1, $fixture->phpunit()['exitCode'], 'control: PHPUnit itself fails');

        $dryRun = $fixture->replay(['run', '--dry-run'], self::env());
        $run = $fixture->replay([], self::env());
        self::assertSame(1, $run['exitCode'], 'false green: ' . $run['stdout'] . $run['stderr']);
        self::assertMatchesRegularExpression('#tests/RateTest\.php\s+← Watch\s+src/Excluded/Rate\.php#u', $dryRun['stdout'], $dryRun['stdout']);
    }

    public function test_a_watch_pattern_selects_its_targets_for_a_file_that_has_edges(): void
    {
        // F6: the PHP-edge rule used to consume a file with edges before any watch pattern
        // saw it, so a pattern naming it never fired.
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;
        $fixture->write('phpunit-replay.php', "<?php\n\nreturn ['watch' => ['src/Money.php' => 'tests/GreeterTest.php']];\n");
        $fixture->repo->commitAll('Greeter depends on Money, says the project');
        self::assertSame(0, $fixture->replay(['record'], self::env())['exitCode']);

        $fixture->write('src/Money.php', $fixture->read('src/Money.php') . "\nfunction money_touched(): void {}\n");
        $fixture->repo->commitAll('Money changes');

        $dryRun = $fixture->replay(['run', '--dry-run'], self::env());
        self::assertMatchesRegularExpression('#tests/GreeterTest\.php\s+← Watch\s+src/Money\.php#u', $dryRun['stdout'], $dryRun['stdout']);
        self::assertMatchesRegularExpression('#tests/MoneyTest\.php\s+← PhpEdge#u', $dryRun['stdout'], $dryRun['stdout']);
    }

    public function test_a_stale_file_is_served_from_an_object_whose_key_and_digest_match(): void
    {
        // The one-time post-upgrade re-run, absorbed by another machine's fresh objects: every
        // result here is unstamped (stale), and the objects prove the same key and digest.
        $shared = $this->dir('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'objects', 'CI' => ''];
        [$m1, $m2] = $this->twoMachines(FixtureProject::plain(TempDir::make('m1') . '/shop'));

        self::assertSame(0, $m1->replay(['record'], $env)['exitCode']);
        self::assertSame(0, $m2->replay(['record'], ['PHPUNIT_REPLAY_REMOTE_PUSH' => 'off', ...array_diff_key($env, ['PHPUNIT_REPLAY_REMOTE_PUSH' => true])])['exitCode']);
        self::stripLocalDigests($m2);

        $run = $m2->replay([], $env);
        self::assertSame(0, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertSame(0, ReplayAssert::executedCount($run['stdout']), ReplayAssert::lastLine($run['stdout']));
        self::assertStringContainsString('35 replayed (35 from remote)', ReplayAssert::lastLine($run['stdout']));
    }

    public function test_push_graph_publishes_past_edits_nothing_reads(): void
    {
        $shared = $this->dir('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'off', 'CI' => ''];
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;
        $fixture->write('README.md', "readme\n");
        $fixture->repo->commitAll('a readme');
        self::assertSame(0, $fixture->replay(['record'], $env)['exitCode']);

        // A README edit and a CI step rewriting phpunit.xml: nothing a result depends on (the
        // fingerprint records phpunit.xml as it is).
        $fixture->write('README.md', "edited\n");
        $fixture->write('phpunit.xml', str_replace('colors="false"', 'colors="true"', $fixture->read('phpunit.xml')));

        $push = $fixture->replay(['push', '--graph'], $env);
        self::assertSame(0, $push['exitCode'], $push['stdout'] . $push['stderr']);
        self::assertNotSame([], glob($shared . '/graph/*/*.json') ?: []);
    }

    public function test_push_uses_the_phpunit_configuration_the_graph_was_recorded_with(): void
    {
        // `push` resolved the default configuration and ignored the `-c` the pass used: its
        // digests differed from the stamps, and no object was published, silently. The pass
        // here is the in-process extension under `phpunit -c <file>`.
        $shared = $this->dir('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'off', 'CI' => ''];
        $fixture = FixtureProject::inprocess();
        $this->fixtures[] = $fixture;
        $fixture->write('phpunit.custom.xml', $fixture->read('phpunit.xml'));
        $fixture->write('phpunit.xml', str_replace('<directory>tests</directory>', '<directory>tests/Nowhere</directory>', $fixture->read('phpunit.xml')));
        $fixture->repo->commitAll('the suite lives in a custom configuration');

        $recorded = $fixture->phpunitInProcess(['-c', 'phpunit.custom.xml'], $env);
        self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);

        $push = $fixture->replay(['push'], $env);
        self::assertSame(0, $push['exitCode'], $push['stdout'] . $push['stderr']);
        self::assertMatchesRegularExpression('/pushed [1-9]\d* object\(s\)/', $push['stdout'], $push['stdout']);
    }

    public function test_a_published_graph_carries_only_results_stamped_for_the_tree_it_is_published_from(): void
    {
        $shared = $this->dir('shared');
        $env = ['PHPUNIT_REPLAY_REMOTE' => 'file://' . $shared, 'PHPUNIT_REPLAY_REMOTE_PUSH' => 'off', 'CI' => ''];
        $fixture = $this->plain($env);

        // A dirty run, then the edit is discarded: the layer still holds the dirty results.
        $fixture->write('src/Money.php', str_replace(
            'return new self($this->amount + $other->amount, $this->currency);',
            'return new self($other->amount + $this->amount, $this->currency);',
            $fixture->read('src/Money.php'),
        ));
        self::assertSame(0, $fixture->replay([], $env)['exitCode']);
        $fixture->repo->git('checkout', '--', 'src/Money.php');

        $push = $fixture->replay(['push', '--graph'], $env);
        self::assertSame(0, $push['exitCode'], $push['stdout'] . $push['stderr']);

        $published = glob($shared . '/graph/*/main.json') ?: [];
        self::assertCount(1, $published);
        $data = json_decode((string) file_get_contents($published[0]), true);
        self::assertIsArray($data);
        $ids = array_keys($data['baselines']['main']['results'] ?? []);

        self::assertSame([], array_values(array_filter($ids, static fn (string $id): bool => str_contains($id, 'MoneyTest'))), 'the dirty run\'s results stayed home');
        self::assertNotSame([], array_values(array_filter($ids, static fn (string $id): bool => str_contains($id, 'GreeterTest'))));
    }

    /** @param array<string, string> $env */
    private function plain(array $env = []): FixtureProject
    {
        $fixture = FixtureProject::plain();
        $this->fixtures[] = $fixture;

        if ($env !== []) {
            $recorded = $fixture->replay(['record'], $env);
            self::assertSame(0, $recorded['exitCode'], $recorded['stdout'] . $recorded['stderr']);
        }

        return $fixture;
    }

    /** @return array{0: FixtureProject, 1: FixtureProject} */
    private function twoMachines(FixtureProject $m1): array
    {
        $m1->repo->git('remote', 'add', 'origin', 'https://example.invalid/acme/shop.git');
        $m2 = $m1->copyTo(TempDir::make('m2') . '/warehouse');
        $this->fixtures[] = $m1;
        $this->fixtures[] = $m2;

        return [$m1, $m2];
    }

    private function dir(string $name): string
    {
        return $this->dirs[] = TempDir::make($name);
    }

    /** What every object written before digests looks like: no `n`, no variants. */
    private static function withoutDigests(string $shared): void
    {
        foreach (glob($shared . '/objects/*/*.json') ?: [] as $path) {
            $object = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($object);
            unset($object['n'], $object['at'], $object['variants']);

            foreach ($object['results'] as $id => $result) {
                unset($object['results'][$id]['digest']);
            }

            file_put_contents($path, json_encode($object, JSON_UNESCAPED_SLASHES));
        }
    }

    private static function stripLocalDigests(FixtureProject $fixture): void
    {
        $path = ReplayAssert::graphPath($fixture);
        $data = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($data);

        foreach ($data['baselines'] as $branch => $baseline) {
            foreach (array_keys($baseline['results']) as $testId) {
                unset($data['baselines'][$branch]['results'][$testId]['n']);
            }
        }

        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string> */
    private static function env(): array
    {
        return ['CI' => ''];
    }
}
