<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Select;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Hermeticity\Policy;
use Manuglopez\Replay\Hermeticity\Quarantine;
use Manuglopez\Replay\PHPUnit\ConfigurationReader;
use Manuglopez\Replay\Select\RunListBuilder;
use Manuglopez\Replay\Select\TestPaths;
use Manuglopez\Replay\Select\WatchPatterns;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The conservative half of `static_declaration_edges` (SPEC.md §9): with the flag on, a
 * changed `.php` file the graph has no edge for is covered by the widest lever the package
 * already has — a literal watch pattern onto every test directory, which `WatchRule` turns
 * into "run everything the graph knows".
 */
#[Group('static-declaration-edges')]
final class RunListBuilderResidueTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('residue');

        $fixture = dirname(__DIR__, 2) . '/Fixtures/Projects/plain/phpunit.xml';
        $xml = file_get_contents($fixture);
        self::assertIsString($xml);
        TempDir::write($this->root . '/phpunit.xml', $xml);

        $this->write('src/Foo.php');
        $this->write('lang/es/validation.php');
        $this->write('tests/FooTest.php');
        $this->write('tests/BarTest.php');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    #[Test]
    public function with_the_flag_off_an_unknown_php_change_still_affects_nothing(): void
    {
        // SPEC.md §7.2.7 — today's behaviour, and what must not move.
        $list = $this->builder(staticDeclarationEdges: false)->build(['lang/es/validation.php'], 'main');

        self::assertSame([], $list->files());
    }

    #[Test]
    public function with_the_flag_off_no_watch_pattern_is_registered_at_all(): void
    {
        $watch = new WatchPatterns();

        $this->builder(staticDeclarationEdges: false, watch: $watch)->build(['lang/es/validation.php'], 'main');

        self::assertSame([], $watch->patterns());
    }

    #[Test]
    public function with_the_flag_on_an_unknown_php_change_selects_every_known_test(): void
    {
        $list = $this->builder(staticDeclarationEdges: true)->build(['lang/es/validation.php'], 'main');

        self::assertSame(['tests/BarTest.php', 'tests/FooTest.php'], $list->files());
    }

    #[Test]
    public function the_reason_reported_is_the_watch_rule_naming_the_file(): void
    {
        $list = $this->builder(staticDeclarationEdges: true)->build(['lang/es/validation.php'], 'main');

        $reasons = $list->reasonsFor('tests/BarTest.php');

        self::assertNotSame([], $reasons);
        self::assertSame('Watch', $reasons[0]->rule);
        self::assertSame('lang/es/validation.php', $reasons[0]->trigger);
        self::assertSame('lang/es/validation.php → tests', $reasons[0]->detail);
    }

    #[Test]
    public function a_file_the_graph_knows_is_left_to_the_php_edge_rule(): void
    {
        // src/Foo.php has an edge to tests/FooTest.php only: it must not be widened into
        // the whole suite just because the flag is on.
        $list = $this->builder(staticDeclarationEdges: true)->build(['src/Foo.php'], 'main');

        self::assertSame(['tests/FooTest.php'], $list->files());
    }

    #[Test]
    public function a_changed_test_file_is_not_residue(): void
    {
        $list = $this->builder(staticDeclarationEdges: true)->build(['tests/BarTest.php'], 'main');

        self::assertSame(['tests/BarTest.php'], $list->files());
    }

    #[Test]
    public function a_non_php_change_is_not_residue(): void
    {
        $this->write('README.md', '# hi');

        $list = $this->builder(staticDeclarationEdges: true)->build(['README.md'], 'main');

        self::assertSame([], $list->files());
    }

    #[Test]
    public function a_blade_change_is_left_to_the_blade_rule_and_the_laravel_watch_defaults(): void
    {
        $this->write('resources/views/mail.blade.php', 'hi');

        $list = $this->builder(staticDeclarationEdges: true)->build(['resources/views/mail.blade.php'], 'main');

        self::assertSame([], $list->files());
    }

    #[Test]
    public function a_deleted_php_file_the_graph_never_knew_is_residue_too(): void
    {
        $list = $this->builder(staticDeclarationEdges: true)->build(['src/GoneForever.php'], 'main');

        self::assertSame(['tests/BarTest.php', 'tests/FooTest.php'], $list->files());
    }

    #[Test]
    public function a_testsuite_built_from_file_entries_gets_the_net_too(): void
    {
        // `<testsuite><file>...</file></testsuite>` leaves TestPaths::directories() empty.
        // Mapping residue onto the directories alone returned no pattern at all, so such a
        // project got no safety net whatsoever while the Recorder was still dropping its
        // load-time-only edges: strictly worse than the flag off, and silent.
        $list = $this->builder(
            staticDeclarationEdges: true,
            testPaths: new TestPaths([], ['tests/FooTest.php', 'tests/BarTest.php'], ['Test.php']),
        )->build(['lang/es/validation.php'], 'main');

        self::assertSame(['tests/BarTest.php', 'tests/FooTest.php'], $list->files());
    }

    #[Test]
    public function a_residue_path_containing_whitespace_matches_its_own_file(): void
    {
        // The pattern key is the changed path itself, and WatchPatterns::parse() splits a key
        // on /\s+/ and reads a leading `!` as an exclude — so `lang/es MX/messages.php`
        // tokenised into the include `lang/es`, which matches nothing at all. The residue
        // pattern was registered and then quietly never fired.
        $this->write('lang/es MX/messages.php');

        $list = $this->builder(staticDeclarationEdges: true)->build(['lang/es MX/messages.php'], 'main');

        self::assertSame(['tests/BarTest.php', 'tests/FooTest.php'], $list->files());
    }

    #[Test]
    public function the_reason_for_a_whitespaced_residue_path_still_names_the_file(): void
    {
        $this->write('lang/es MX/messages.php');

        $list = $this->builder(staticDeclarationEdges: true)->build(['lang/es MX/messages.php'], 'main');
        $reasons = $list->reasonsFor('tests/BarTest.php');

        self::assertNotSame([], $reasons);
        self::assertSame('Watch', $reasons[0]->rule);
        self::assertSame('lang/es MX/messages.php', $reasons[0]->trigger);
        self::assertSame('lang/es MX/messages.php → tests', $reasons[0]->detail);
    }

    private function builder(
        bool $staticDeclarationEdges,
        ?WatchPatterns $watch = null,
        ?TestPaths $testPaths = null,
    ): RunListBuilder {
        $graph = new Graph($this->root);
        $graph->link($this->root . '/tests/FooTest.php', $this->root . '/src/Foo.php');
        $graph->markKnownTestFiles([$this->root . '/tests/BarTest.php']);

        return new RunListBuilder(
            $graph,
            $testPaths ?? new TestPaths(['tests'], [], ['Test.php']),
            $watch ?? new WatchPatterns(),
            ConfigurationReader::fromXmlFile($this->root . '/phpunit.xml'),
            new Policy($graph, Config::defaults(), Quarantine::load($this->root . '/state'), $this->root),
            $this->root,
            [],
            $staticDeclarationEdges,
        );
    }

    private function write(string $relative, string $content = "<?php\n"): void
    {
        TempDir::write($this->root . '/' . $relative, $content);
    }
}
