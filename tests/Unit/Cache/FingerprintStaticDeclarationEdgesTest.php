<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache;

use Manuglopez\Replay\Analysis\DeclarationScanner;
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
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', false);

        self::assertSame(
            ['schema', 'edges_exclude_ignored', 'edges_by_running_class', 'composer_lock', 'phpunit_xml', 'phpunit_xml_dist', 'replay_config'],
            array_keys($fingerprint['structural']),
        );
        self::assertArrayNotHasKey('analysis_rules', $fingerprint['structural']);
    }

    #[Test]
    public function with_the_flag_off_the_content_key_material_is_byte_identical(): void
    {
        // Cache\ContentKey hashes exactly this string as the structural segment of its
        // material (`Fingerprint::canonicalResultEnvironment()` contributes the second
        // segment). If it moved, every cached key on every machine in the world would be
        // invalidated by upgrading. It DID move twice,
        // deliberately: for `edges_exclude_ignored` (Fingerprint's own class docblock,
        // "edges_exclude_ignored" section) — a graph is contaminated by definition once an
        // edge can point at a file git ignores, so every graph on every machine had to be
        // invalidated exactly once — and again for `edges_by_running_class` ("edges_by_running_class"
        // section): an edge attributed to an abstract base's declaring file instead of the
        // concrete running class's file is exactly as contaminated, for the same reason.
        self::assertSame(
            '{"composer_lock":null,"edges_by_running_class":true,"edges_exclude_ignored":true,"phpunit_xml":null,"phpunit_xml_dist":null,"replay_config":null,"schema":1}',
            Fingerprint::canonicalStructural(Fingerprint::compute($this->repo->root, 'pcov', false)),
        );
        self::assertSame(
            Fingerprint::canonicalStructural(Fingerprint::compute($this->repo->root, 'pcov', false)),
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
    public function with_the_flag_on_the_classification_rules_version_rides_along(): void
    {
        // Without this, bumping RULES_VERSION re-parsed <stateDir>/analysis/ and changed no
        // content key, so it re-ran no test and never corrected an edge the old rules got
        // wrong — `Graph::unionEdges()` only grows, so a dropped edge stays dropped.
        $fingerprint = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame(DeclarationScanner::RULES_VERSION, $fingerprint['structural']['analysis_rules']);
        self::assertStringContainsString(
            '"analysis_rules":' . DeclarationScanner::RULES_VERSION,
            Fingerprint::canonicalStructural($fingerprint),
        );
    }

    #[Test]
    public function a_graph_recorded_under_older_classification_rules_is_structural_drift(): void
    {
        $stored = Fingerprint::compute($this->repo->root, 'pcov', true);
        $stored['structural']['analysis_rules'] = DeclarationScanner::RULES_VERSION - 1;

        $current = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame(['analysis_rules'], Fingerprint::structuralDrift($stored, $current));
        self::assertFalse(Fingerprint::structuralMatches($stored, $current));
    }

    #[Test]
    public function flipping_the_flag_on_is_structural_drift(): void
    {
        $off = Fingerprint::compute($this->repo->root, 'pcov', false);
        $on = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame(['static_declaration_edges', 'analysis_rules'], Fingerprint::structuralDrift($off, $on));
        self::assertFalse(Fingerprint::structuralMatches($off, $on));
    }

    #[Test]
    public function flipping_the_flag_off_again_is_structural_drift_too(): void
    {
        // Symmetric on purpose: a graph recorded with static edges must not be read back
        // by a pass that no longer produces them.
        $off = Fingerprint::compute($this->repo->root, 'pcov', false);
        $on = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame(['static_declaration_edges', 'analysis_rules'], Fingerprint::structuralDrift($on, $off));
    }

    #[Test]
    public function the_flag_never_shows_up_as_environmental_drift(): void
    {
        $off = Fingerprint::compute($this->repo->root, 'pcov', false);
        $on = Fingerprint::compute($this->repo->root, 'pcov', true);

        self::assertSame([], Fingerprint::environmentalDrift($off, $on));
    }
}
