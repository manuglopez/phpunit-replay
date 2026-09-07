<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Change;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\Remote\FilesystemRemoteCache;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Change\BaselineResolver;
use Manuglopez\Replay\Change\Git;
use Manuglopez\Replay\Config;
use Manuglopez\Replay\Tests\Support\GitRepo;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * DECISIONS.md D-039 / docs/INTERNALS.md "Nearest baseline", against a real git-flow
 * history:
 *
 *   main ──● (main baseline)
 *          ├── develop ──● (develop baseline) ── feature/checkout
 *          └── hotfix/tax
 */
final class BaselineResolverTest extends TestCase
{
    private GitRepo $repo;

    private string $mainSha = '';

    private string $developSha = '';

    protected function setUp(): void
    {
        $this->repo = GitRepo::init();
        $this->repo->write('src/Money.php', "<?php\n");
        $this->mainSha = $this->repo->commitAll('main');

        $this->repo->checkout('develop', create: true);
        $this->repo->write('src/Cart.php', "<?php\n");
        $this->developSha = $this->repo->commitAll('develop');

        $this->repo->checkout('feature/checkout', create: true);
        $this->repo->write('src/Checkout.php', "<?php\n");
        $this->repo->commitAll('feature');

        $this->repo->checkout('main');
        $this->repo->checkout('hotfix/tax', create: true);
        $this->repo->write('src/Tax.php', "<?php\n");
        $this->repo->commitAll('hotfix');
    }

    protected function tearDown(): void
    {
        $this->repo->destroy();
    }

    public function testAFeatureBranchInheritsTheNearestBaselineDevelop(): void
    {
        $this->repo->checkout('feature/checkout');

        $resolved = $this->resolver()->resolve('feature/checkout', $this->repo->sha());

        self::assertNotNull($resolved);
        self::assertSame('develop', $resolved['branch']);
        self::assertSame($this->developSha, $resolved['sha']);
        self::assertSame('local', $resolved['source']);
        self::assertSame(1, $resolved['distance']);
    }

    public function testAHotfixBranchInheritsMainBecauseDevelopIsNotAnAncestor(): void
    {
        $this->repo->checkout('hotfix/tax');

        $resolved = $this->resolver()->resolve('hotfix/tax', $this->repo->sha());

        self::assertNotNull($resolved);
        self::assertSame('main', $resolved['branch']);
        self::assertSame($this->mainSha, $resolved['sha']);
        self::assertSame(1, $resolved['distance']);
    }

    public function testTheBranchOwnBaselineWinsWhenItIsAtLeastAsClose(): void
    {
        $this->repo->checkout('feature/checkout');
        $head = $this->repo->sha();

        $graph = $this->graph();
        // The feature branch has recorded its own baseline at HEAD: distance 0 beats
        // develop's 1, and even a tie goes to the branch's own baseline.
        $graph->setRecordedSha('feature/checkout', $head);

        $resolved = $this->resolver($graph)->resolve('feature/checkout', $head);

        self::assertNotNull($resolved);
        self::assertSame('feature/checkout', $resolved['branch']);
        self::assertSame('own', $resolved['source']);
        self::assertSame(0, $resolved['distance']);
    }

    public function testOwnBaselineWinsATieAgainstAnEquallyCloseCandidate(): void
    {
        $this->repo->checkout('develop');
        $head = $this->repo->sha();

        $graph = $this->graph();
        $graph->setRecordedSha('develop', $head);
        $graph->setRecordedSha('main', $head);

        $resolved = $this->resolver($graph)->resolve('develop', $head);

        self::assertNotNull($resolved);
        self::assertSame('develop', $resolved['branch']);
        self::assertSame('own', $resolved['source']);
    }

    public function testNoCandidateIsAnAncestorSoNothingResolves(): void
    {
        $this->repo->checkout('main');
        $head = $this->repo->sha();

        // develop is ahead of main, so its baseline is not an ancestor of main's HEAD;
        // main itself has no baseline in this graph.
        $graph = new Graph($this->repo->root);
        $graph->setRecordedSha('develop', $this->developSha);

        $config = Config::defaults()->with(['baselineBranches' => ['develop']]);

        self::assertNull((new BaselineResolver(new Git($this->repo->root), $graph, null, $config, 'main'))->resolve('main', $head));
    }

    public function testAnUnknownCandidateBranchIsSkipped(): void
    {
        $this->repo->checkout('feature/checkout');
        $head = $this->repo->sha();

        $config = Config::defaults()->with(['baselineBranches' => ['release/9.9', 'develop']]);
        $resolver = new BaselineResolver(new Git($this->repo->root), $this->graph(), null, $config, 'main');

        $resolved = $resolver->resolve('feature/checkout', $head);

        self::assertNotNull($resolved);
        self::assertSame('develop', $resolved['branch']);
    }

    public function testTheRemoteSuppliesAShaTheLocalGraphNeverRecorded(): void
    {
        $this->repo->checkout('feature/checkout');
        $head = $this->repo->sha();

        $remoteRoot = TempDir::make('baseline-remote');
        $stateDir = TempDir::make('baseline-state');

        try {
            $published = new Graph($this->repo->root);
            $published->setRecordedSha('develop', $this->developSha);
            $body = $published->encode();
            self::assertNotNull($body);

            $backend = new FilesystemRemoteCache($remoteRoot);
            $backend->begin();
            $store = new ObjectStore($backend, $stateDir, 'shop-abc');
            self::assertTrue($store->putGraph('develop', $body));

            // The local graph knows nothing about develop.
            $local = new Graph($this->repo->root);
            $config = Config::defaults()->with(['baselineBranches' => ['develop', 'main']]);
            $resolved = (new BaselineResolver(new Git($this->repo->root), $local, $store, $config, 'main'))
                ->resolve('feature/checkout', $head);

            self::assertNotNull($resolved);
            self::assertSame('develop', $resolved['branch']);
            self::assertSame($this->developSha, $resolved['sha']);
            self::assertSame('remote', $resolved['source']);
            self::assertSame(1, $resolved['distance']);
        } finally {
            TempDir::remove($remoteRoot);
            TempDir::remove($stateDir);
        }
    }

    public function testCandidatesPutTheBranchItselfFirstAndDeduplicate(): void
    {
        $config = Config::defaults()->with(['baselineBranches' => ['develop', 'main', 'develop']]);
        $resolver = new BaselineResolver(new Git($this->repo->root), $this->graph(), null, $config, 'main');

        self::assertSame(['develop', 'main'], $resolver->candidates('develop'));
        self::assertSame(['feature/x', 'develop', 'main'], $resolver->candidates('feature/x'));
    }

    public function testCandidatesFallBackToTheDetectedDefaultBranch(): void
    {
        $resolver = new BaselineResolver(new Git($this->repo->root), $this->graph(), null, Config::defaults(), 'main');

        self::assertSame(['feature/x', 'main'], $resolver->candidates('feature/x'));
    }

    public function testDescribeOnlyNamesABaselineFromAnotherBranch(): void
    {
        $baseline = ['branch' => 'develop', 'sha' => 'abc1234def', 'source' => 'local', 'distance' => 3];

        self::assertSame(
            'baseline develop@abc1234 (nearest, 3 files away)',
            BaselineResolver::describe($baseline, 'feature/checkout'),
        );
        self::assertSame(
            'baseline develop@abc1234 (nearest, 1 file away)',
            BaselineResolver::describe([...$baseline, 'distance' => 1], 'feature/checkout'),
        );
        self::assertNull(BaselineResolver::describe($baseline, 'develop'));
    }

    private function resolver(?Graph $graph = null): BaselineResolver
    {
        $config = Config::defaults()->with(['baselineBranches' => ['develop', 'main']]);

        return new BaselineResolver(new Git($this->repo->root), $graph ?? $this->graph(), null, $config, 'main');
    }

    /** A graph with recorded baselines for both long-lived branches. */
    private function graph(): Graph
    {
        $graph = new Graph($this->repo->root);
        $graph->setRecordedSha('main', $this->mainSha);
        $graph->markBaselineComplete('main');
        $graph->setRecordedSha('develop', $this->developSha);
        $graph->markBaselineComplete('develop');

        return $graph;
    }
}
