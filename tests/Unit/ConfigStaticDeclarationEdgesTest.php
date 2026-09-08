<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit;

use Manuglopez\Replay\Config;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('static-declaration-edges')]
final class ConfigStaticDeclarationEdgesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempDir::make('config-static-edges');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->root);
    }

    #[Test]
    public function it_defaults_to_off(): void
    {
        // Non-negotiable: turning this on changes every content key, so it can only ever
        // be something the user asked for.
        self::assertFalse(Config::defaults()->staticDeclarationEdges);
        self::assertFalse(Config::fromArray([])->staticDeclarationEdges);
        self::assertFalse(Config::load($this->root)->staticDeclarationEdges);
    }

    #[Test]
    public function the_config_file_key_turns_it_on(): void
    {
        self::assertTrue(Config::fromArray(['static_declaration_edges' => true])->staticDeclarationEdges);

        TempDir::write($this->root . '/phpunit-replay.php', "<?php\n\nreturn ['static_declaration_edges' => true];\n");

        self::assertTrue(Config::load($this->root)->staticDeclarationEdges);
    }

    #[Test]
    public function a_non_boolean_value_falls_back_to_the_default(): void
    {
        self::assertFalse(Config::fromArray(['static_declaration_edges' => 'yes'])->staticDeclarationEdges);
        self::assertFalse(Config::fromArray(['static_declaration_edges' => 1])->staticDeclarationEdges);
        self::assertFalse(Config::fromArray(['static_declaration_edges' => null])->staticDeclarationEdges);
    }

    #[Test]
    public function the_environment_variable_wins_in_both_directions(): void
    {
        // The wrapper sets this on the PHPUnit child it spawns; the child (and every
        // Paratest worker under it) reads nothing else.
        self::assertTrue(
            Config::defaults()->mergeEnv(['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES' => '1'])->staticDeclarationEdges,
        );
        self::assertFalse(
            Config::fromArray(['static_declaration_edges' => true])
                ->mergeEnv(['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES' => '0'])
                ->staticDeclarationEdges,
        );
    }

    #[Test]
    public function an_unset_or_unrecognised_environment_value_keeps_the_current_setting(): void
    {
        $on = Config::fromArray(['static_declaration_edges' => true]);

        self::assertTrue($on->mergeEnv([])->staticDeclarationEdges);
        self::assertTrue($on->mergeEnv(['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES' => 'true'])->staticDeclarationEdges);
        self::assertTrue($on->mergeEnv(['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES' => ''])->staticDeclarationEdges);
        self::assertFalse(Config::defaults()->mergeEnv(['PHPUNIT_REPLAY_STATIC_DECLARATION_EDGES' => 'yes'])->staticDeclarationEdges);
    }

    #[Test]
    public function with_returns_a_copy_carrying_the_flag(): void
    {
        $config = Config::defaults()->with(['staticDeclarationEdges' => true]);

        self::assertTrue($config->staticDeclarationEdges);
        self::assertTrue($config->with([])->staticDeclarationEdges, 'an unrelated with() must not reset it');
        self::assertTrue($config->with(['mode' => 'record'])->staticDeclarationEdges);
        self::assertFalse($config->with(['staticDeclarationEdges' => false])->staticDeclarationEdges);
    }
}
