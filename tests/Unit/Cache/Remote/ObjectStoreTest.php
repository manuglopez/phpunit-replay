<?php

declare(strict_types=1);

namespace Manuglopez\Replay\Tests\Unit\Cache\Remote;

use Manuglopez\Replay\Cache\Graph;
use Manuglopez\Replay\Cache\Remote\FilesystemRemoteCache;
use Manuglopez\Replay\Cache\Remote\HttpRemoteCache;
use Manuglopez\Replay\Cache\Remote\NullRemoteCache;
use Manuglopez\Replay\Cache\Remote\ObjectStore;
use Manuglopez\Replay\Support\Json;
use Manuglopez\Replay\Tests\Support\TempDir;
use PHPUnit\Framework\TestCase;

final class ObjectStoreTest extends TestCase
{
    private string $tmp;

    private string $stateDir;

    private string $remoteRoot;

    protected function setUp(): void
    {
        $this->tmp = TempDir::make('object-store');
        $this->stateDir = $this->tmp . '/state';
        $this->remoteRoot = $this->tmp . '/remote';
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->tmp);
    }

    public function testKeyShapes(): void
    {
        self::assertSame('graph/shop-abc/main.json', ObjectStore::graphKey('shop-abc', 'main'));
        self::assertSame('objects/2026-09/deadbeef.json', ObjectStore::objectKey('2026-09', 'deadbeef'));

        // A branch name is one key, not a tree: slashes are flattened.
        self::assertSame('graph/shop-abc/feature-x.json', ObjectStore::graphKey('shop-abc', 'feature/x'));
    }

    public function testTheNullBackendIsReportedAsDisabled(): void
    {
        $store = new ObjectStore(new NullRemoteCache(), $this->stateDir, 'shop-abc');

        self::assertFalse($store->enabled());
        self::assertNull($store->graph('main'));
        self::assertNull($store->object('deadbeef'));
        self::assertFalse($store->putObject('deadbeef', 'tests/MoneyTest.php', $this->results()));
        self::assertFalse($store->putGraph('main', '{}'));
    }

    public function testTheFilesystemBackendIsReportedAsEnabled(): void
    {
        self::assertTrue($this->store()->enabled());
    }

    public function testAnObjectRoundTripsThroughTheCurrentMonthShard(): void
    {
        $store = $this->store();

        self::assertTrue($store->putObject('deadbeef', 'tests/MoneyTest.php', $this->results()));

        $shard = ObjectStore::currentShard();
        $path = $this->remoteRoot . '/' . ObjectStore::objectKey($shard, 'deadbeef');
        self::assertFileExists($path);

        $body = Json::decodeArray((string) file_get_contents($path));
        self::assertIsArray($body);
        self::assertSame('deadbeef', $body['k'] ?? null);
        self::assertSame('tests/MoneyTest.php', $body['file'] ?? null);
        self::assertSame(
            ['status' => 0, 'message' => '', 'time' => 0.5, 'assertions' => 2, 'file' => 'tests/MoneyTest.php'],
            $body['results']['App\\Tests\\MoneyTest::test_adds'] ?? null,
        );

        // A second store (a second machine, its own state dir) reads it back.
        $reader = new ObjectStore($this->backend(), $this->tmp . '/state2', 'shop-abc');
        $object = $reader->object('deadbeef');

        self::assertNotNull($object);
        self::assertSame('deadbeef', $object['k']);
        self::assertSame('tests/MoneyTest.php', $object['file']);
        self::assertSame($this->results(), $object['results']);
    }

    public function testAReadIsMirroredLocallyAndSurvivesTheRemoteGoingAway(): void
    {
        $this->store()->putObject('deadbeef', 'tests/MoneyTest.php', $this->results());

        $reader = new ObjectStore($this->backend(), $this->tmp . '/state2', 'shop-abc');
        self::assertNotNull($reader->object('deadbeef'));
        self::assertFileExists($reader->mirrorPath('deadbeef'));

        TempDir::remove($this->remoteRoot);

        self::assertNotNull($reader->object('deadbeef'), 'the local read-through mirror should answer');
    }

    public function testAnObjectInAPreviousMonthShardIsFoundByName(): void
    {
        $backend = $this->backend();
        $backend->begin();

        $store = new ObjectStore($backend, $this->stateDir, 'shop-abc');
        $shards = $store->shards();
        self::assertCount(6, $shards);

        $body = (string) Json::encode(['k' => 'oldkey', 'file' => 'tests/CartTest.php', 'results' => $this->results()]);
        $backend->put(ObjectStore::objectKey($shards[5], 'oldkey'), $body);

        $object = $store->object('oldkey');

        self::assertNotNull($object);
        self::assertSame('tests/CartTest.php', $object['file']);
    }

    public function testAnObjectOlderThanTheLookbackWindowIsFoundByListing(): void
    {
        $backend = $this->backend();
        $backend->begin();

        $body = (string) Json::encode(['k' => 'ancient', 'file' => 'tests/CartTest.php', 'results' => $this->results()]);
        $backend->put(ObjectStore::objectKey('2019-01', 'ancient'), $body);

        $store = new ObjectStore($backend, $this->stateDir, 'shop-abc');

        self::assertNotNull($store->object('ancient'));
    }

    public function testABackendWithoutListingSimplyMissesTheOldShard(): void
    {
        // HttpRemoteCache::keys() returns [] by design (no portable listing over GET/PUT/
        // HEAD/DELETE), so the six-shard probe is all such a backend gets — and a miss is
        // just a miss, never an error.
        $http = HttpRemoteCache::fromRemote('http://127.0.0.1:9/cache/');
        self::assertNotNull($http);

        $store = new ObjectStore($http, $this->stateDir, 'shop-abc');

        self::assertNull($store->object('ancient'));
    }

    public function testPublishingAKeyThisMachineAlreadyPublishedIsSkipped(): void
    {
        $store = $this->store();

        self::assertTrue($store->putObject('deadbeef', 'tests/MoneyTest.php', $this->results()));
        self::assertFalse($store->putObject('deadbeef', 'tests/MoneyTest.php', $this->results()));
    }

    public function testAnEmptyKeyOrEmptyResultsArePublishedNowhere(): void
    {
        $store = $this->store();

        self::assertFalse($store->putObject('', 'tests/MoneyTest.php', $this->results()));
        self::assertFalse($store->putObject('deadbeef', 'tests/MoneyTest.php', []));
        self::assertNull($store->object(''));
    }

    public function testABodyWhoseKeyDoesNotMatchIsIgnored(): void
    {
        $backend = $this->backend();
        $backend->begin();
        $backend->put(
            ObjectStore::objectKey(ObjectStore::currentShard(), 'deadbeef'),
            (string) Json::encode(['k' => 'somethingelse', 'file' => 'tests/MoneyTest.php', 'results' => $this->results()]),
        );

        $store = new ObjectStore($backend, $this->stateDir, 'shop-abc');

        self::assertNull($store->object('deadbeef'));
    }

    public function testGarbageAndEmptySectionsAreIgnored(): void
    {
        $backend = $this->backend();
        $backend->begin();
        $shard = ObjectStore::currentShard();

        $backend->put(ObjectStore::objectKey($shard, 'notjson'), 'this is not json');
        $backend->put(ObjectStore::objectKey($shard, 'noresults'), (string) Json::encode(['k' => 'noresults', 'file' => 'tests/A.php', 'results' => []]));
        $backend->put(ObjectStore::objectKey($shard, 'nofile'), (string) Json::encode(['k' => 'nofile', 'results' => $this->results()]));

        $store = new ObjectStore($backend, $this->stateDir, 'shop-abc');

        self::assertNull($store->object('notjson'));
        self::assertNull($store->object('noresults'));
        self::assertNull($store->object('nofile'));
    }

    public function testAGraphRoundTripsAndIsDecodable(): void
    {
        $graph = new Graph($this->tmp);
        $graph->link($this->tmp . '/tests/MoneyTest.php', $this->tmp . '/src/Money.php');
        $graph->setRecordedSha('main', 'a1b2c3d4e5f6a7b8c9d0');
        $graph->markBaselineComplete('main');
        $encoded = $graph->encode();
        self::assertNotNull($encoded);

        $store = $this->store();

        self::assertTrue($store->putGraph('main', $encoded));
        self::assertFileExists($this->remoteRoot . '/graph/shop-abc/main.json');
        self::assertSame($encoded, $store->graph('main'));

        $decoded = $store->graphOf('main', $this->tmp);
        self::assertNotNull($decoded);
        self::assertSame('a1b2c3d4e5f6a7b8c9d0', $decoded->ownRecordedSha('main'));
        self::assertSame(['tests/MoneyTest.php'], $decoded->allTestFiles());
    }

    public function testAMissingGraphIsNullAndTheLookupIsMemoised(): void
    {
        $store = $this->store();

        self::assertNull($store->graph('develop'));
        self::assertNull($store->graphOf('develop', $this->tmp));

        // Even after the key appears, this run keeps the answer it already got: one
        // download per branch per pass.
        $this->backend()->put('graph/shop-abc/develop.json', '{"schema":1}');
        self::assertNull($store->graph('develop'));
    }

    /** @return array<string, array{status:int, message:string, time:float, assertions:int, file:string}> */
    private function results(): array
    {
        return [
            'App\\Tests\\MoneyTest::test_adds' => [
                'status' => 0,
                'message' => '',
                'time' => 0.5,
                'assertions' => 2,
                'file' => 'tests/MoneyTest.php',
            ],
        ];
    }

    private function store(): ObjectStore
    {
        $backend = $this->backend();
        $backend->begin();

        return new ObjectStore($backend, $this->stateDir, 'shop-abc');
    }

    private function backend(): FilesystemRemoteCache
    {
        return new FilesystemRemoteCache($this->remoteRoot);
    }
}
