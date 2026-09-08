<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Cache\Fingerprint;
use Manuglopez\Replay\Tests\Support\GitRepo;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `static_declaration_edges` changes what an edge means, so it belongs in the structural
 * bucket — the one that feeds every content key and discards the graph, not the
 * environmental one that keeps the edges and throws away the results.
 *
 * It is present only when the flag is on. That is the whole reason the flag can ship
 * without touching a single existing cache anywhere.
 */
#[Group('static-declaration-edges')]
final class FingerprintStaticDeclarationEdgesTest extends TestCase
{
    private GitRepo $repo;

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    #[Test]
    public function with_the_flag_off_the_structural_bucket_is_exactly_what_it_always_was(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov');

        self::assertSame(
            ['schema', 'composer_lock', 'phpunit_xml', 'phpunit_xml_dist', 'replay_config'],
            array_keys($fingerprint['structural']),
        );
    }

    #[Test]
    public function with_the_flag_off_the_content_key_material_is_byte_identical(): void
    {
        // Cache\ContentKey hashes exactly this string. If it moved, every cached key on
        // every machine in the world would be invalidated by upgrading.
        self::assertSame(
            '{"composer_lock":null,"phpunit_xml":null,"phpunit_xml_dist":null,"replay_config":null,"schema":1}',
            Fingerprint::canonicalStructural(Fingerprint::compute($this->repo->root, 'pcov')),
        );
        self::assertSame(
            Fingerprint::canonicalStructural(Fingerprint::compute($this->repo->root, 'pcov')),
            Fingerprint::canonicalStructural(Fingerprint::compute($this->repo->root, 'pcov', false)),
        );
    }

    #[Test]
    public function with_the_flag_on_the_key_is_added_to_the_structural_bucket(): void
    {
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertTrue($fingerprint['structural']['static_declaration_edges']);
        self::assertArrayNotHasKey('static_declaration_edges', $fingerprint['environmental']);
        self::assertStringContainsString(
            '"static_declaration_edges":true',
            Fingerprint::canonicalStructural($fingerprint),
        );
    }

    #[Test]
    public function flipping_the_flag_on_is_structural_drift(): void
    {
        $off = Fingerprint::compute($this->repo->root, 'pcov');
        $on = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame(['static_declaration_edges'], Fingerprint::structuralDrift($off, $on));
        self::assertFalse(Fingerprint::structuralMatches($off, $on));
    }

    #[Test]
    public function flipping_the_flag_off_again_is_structural_drift_too(): void
    {
        // Symmetric on purpose: a graph recorded with static edges must not be read back
        // by a pass that no longer produces them.
        $off = Fingerprint::compute($this->repo->root, 'pcov');
        $on = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame(['static_declaration_edges'], Fingerprint::structuralDrift($on, $off));
    }

    #[Test]
    public function the_flag_never_shows_up_as_environmental_drift(): void
    {
        $off = Fingerprint::compute($this->repo->root, 'pcov');
        $on = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame([], Fingerprint::environmentalDrift($off, $on));
    }
}
