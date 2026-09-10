<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache\Remote;

use Manuglopez\Replay\Cache\Remote\OriginUrl;
use Manuglopez\Replay\Cache\Remote\RemoteCacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Cache\Remote\OriginUrl`: the origin forms `remote:init` accepts, and the sibling URL it
 * proposes for each of them (docs/sharing-the-cache.md "Setup: dedicated git repository").
 */
final class OriginUrlTest extends TestCase
{
    /**
     * @return list<array{string, string, string, string, string}>
     */
    public static function originForms(): array
    {
        return [
            // origin, host, owner, name, proposed sibling
            ['git@github.com:acme/widget.git', 'github.com', 'acme', 'widget', 'git@github.com:acme/widget-replay-cache.git'],
            ['git@github.com:acme/widget', 'github.com', 'acme', 'widget', 'git@github.com:acme/widget-replay-cache.git'],
            ['https://github.com/acme/widget.git', 'github.com', 'acme', 'widget', 'https://github.com/acme/widget-replay-cache.git'],
            ['https://github.com/acme/widget', 'github.com', 'acme', 'widget', 'https://github.com/acme/widget-replay-cache.git'],
            ['https://user@github.com/acme/widget.git', 'github.com', 'acme', 'widget', 'https://user@github.com/acme/widget-replay-cache.git'],
            ['ssh://git@github.com/acme/widget.git', 'github.com', 'acme', 'widget', 'ssh://git@github.com/acme/widget-replay-cache.git'],
            ['ssh://git@ssh.example.com:2222/acme/widget.git', 'ssh.example.com', 'acme', 'widget', 'ssh://git@ssh.example.com:2222/acme/widget-replay-cache.git'],
            ['git+ssh://git@example.com/acme/widget.git', 'example.com', 'acme', 'widget', 'ssh://git@example.com/acme/widget-replay-cache.git'],
            ['git+https://example.com/acme/widget.git', 'example.com', 'acme', 'widget', 'https://example.com/acme/widget-replay-cache.git'],
            // A subgroup namespace stays in the owner instead of being flattened into the name.
            ['https://gitlab.com/group/sub/widget.git', 'gitlab.com', 'group/sub', 'widget', 'https://gitlab.com/group/sub/widget-replay-cache.git'],
            // The local bare-repository forms GitRemoteCache documents for tests.
            ['file:///srv/repos/acme/widget.git', '', 'srv/repos/acme', 'widget', 'file:///srv/repos/acme/widget-replay-cache.git'],
            ['/srv/repos/acme/widget.git', '', 'srv/repos/acme', 'widget', '/srv/repos/acme/widget-replay-cache.git'],
            // No owner at all: still a well-formed proposal.
            ['git@example.com:widget.git', 'example.com', '', 'widget', 'git@example.com:widget-replay-cache.git'],
        ];
    }

    #[DataProvider('originForms')]
    public function testEachAcceptedFormYieldsHostOwnerNameAndASibling(
        string $url,
        string $host,
        string $owner,
        string $name,
        string $sibling,
    ): void {
        $origin = OriginUrl::parse($url);

        self::assertNotNull($origin, $url . ' should parse');
        self::assertSame($url, $origin->url);
        self::assertSame($host, $origin->host);
        self::assertSame($owner, $origin->owner);
        self::assertSame($name, $origin->name);
        self::assertSame($sibling, $origin->sibling($origin->owner, $origin->name . '-replay-cache'));
    }

    /**
     * Every URL this proposes has to be routed to the git backend, which is what the `.git`
     * suffix is for: `https://host/owner/name` with no suffix is the HTTP backend.
     */
    public function testEveryProposedUrlIsRecognisedAsGitByTheFactory(): void
    {
        foreach (self::originForms() as [$url]) {
            $origin = OriginUrl::parse($url);

            self::assertNotNull($origin, $url);
            self::assertTrue(
                RemoteCacheFactory::looksLikeGit($origin->sibling($origin->owner, $origin->name . '-replay-cache')),
                $url . ' proposes a sibling the factory would not route to the git backend',
            );
            self::assertTrue(RemoteCacheFactory::looksLikeGit($origin->canonical()), $url);
        }
    }

    public function testCanonicalIsOriginItselfWithAGuaranteedGitSuffix(): void
    {
        $suffixed = OriginUrl::parse('https://github.com/acme/widget.git');
        $bare = OriginUrl::parse('https://github.com/acme/widget');

        self::assertNotNull($suffixed);
        self::assertNotNull($bare);
        self::assertSame('https://github.com/acme/widget.git', $suffixed->canonical());
        self::assertSame('https://github.com/acme/widget.git', $bare->canonical());
    }

    public function testTheIdentityIsTheOneTheSharedProjectKeyHashes(): void
    {
        $origin = OriginUrl::parse('git@github.com:Acme/Widget.git');

        self::assertNotNull($origin);
        self::assertSame('github.com/acme/widget', $origin->identity());
    }

    /**
     * @return list<array{string}>
     */
    public static function unparseableOrigins(): array
    {
        return [
            [''],
            ['   '],
            ['not a url'],
            ['https://github.com/'],
            ['git@github.com:'],
            ['git@github.com:.git'],
        ];
    }

    #[DataProvider('unparseableOrigins')]
    public function testAnOriginItCannotSplitIsNullRatherThanAThrow(string $url): void
    {
        self::assertNull(OriginUrl::parse($url));
    }
}
