<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Integration;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\ModelCache\Context\RequestScope;
use Hyperf\ModelCache\Handler\RedisStringHandler;
use Hyperf\ModelCache\Handler\SnapshotRedisHandler;
use Hyperf\ModelCache\Invalidation\RedisTableCacheInvalidator;
use Hyperf\ModelCache\Manager;
use HyperfTest\ModelCache\Support\Fixture;
use HyperfTest\ModelCache\Support\TestEntity;
use PHPUnit\Framework\TestCase;

final class RuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('MODEL_CACHE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Set MODEL_CACHE_INTEGRATION=1 with isolated services.');
        }
        Fixture::container();
        $pdo = Fixture::pdo();
        $pdo->exec('DELETE FROM mc_entities');
        $pdo->exec("INSERT INTO mc_entities(id,name,amount,secret,payload) VALUES(1,'one',1,'hidden',NULL),(2,'two',2,'secret',X'00FF01')");
        $this->clearTableCache();
        Fixture::$selects = 0;
    }
    private function clearTableCache(): int
    {
        return (new RedisTableCacheInvalidator(2))->invalidate(['version' => 1, 'tables' => [[
            'pool' => 'default', 'template' => '{mc:%s:m:%s}:%s:%s', 'prefix' => 'mc_integration', 'table' => 'mc_entities', 'primary_key' => 'id',
        ]]], ['default' => Fixture::redis()], true);
    }
    public function testContextSnapshotIsolatedAndInvisibleNullableBinaryFieldsRoundTrip(): void
    {
        RequestScope::run(function (): void {
            $one = TestEntity::findFromCache(1);
            $one->name = 'unsaved';
            $two = TestEntity::findFromCache(1);
            self::assertNotSame($one, $two);
            self::assertSame('one', $two->name);
            self::assertSame('hidden', $two->secret);
            self::assertNull($two->payload);
            self::assertSame(1, Fixture::$selects);
        });
        RequestScope::run(function (): void {
            self::assertSame('one', TestEntity::findFromCache(1)->name);
            self::assertSame(1, Fixture::$selects); // Redis across requests.
            self::assertSame("\x00\xff\x01", TestEntity::findFromCache(2)->payload);
        });
        self::assertArrayNotHasKey('secret', TestEntity::query()->find(1)->getAttributes());
    }
    public function testBatchAndSingleShareSnapshotsAndMissingIds(): void
    {
        RequestScope::run(function (): void {
            TestEntity::findFromCache(1);
            $models = TestEntity::findManyFromCache([2, 1, 404, 2]);
            self::assertSame([2, 1], array_map('intval', $models->pluck('id')->all()));
            self::assertNull(TestEntity::findFromCache(404));
            self::assertSame('two', TestEntity::findFromCache(2)->name);
            self::assertSame(2, Fixture::$selects);
        });
        RequestScope::run(function (): void {
            self::assertNull(TestEntity::findFromCache(404));
        });
        self::assertSame(2, Fixture::$selects);
    }
    public function testSavedDeletedAndBatchWritesInvalidateBothLayers(): void
    {
        RequestScope::run(function (): void {
            $model = TestEntity::findFromCache(1);
            $model->name = 'saved';
            $model->save();
            self::assertSame('saved', TestEntity::findFromCache(1)->name);
            TestEntity::query(true)->where('id', 1)->update(['name' => 'batch']);
            self::assertSame('batch', TestEntity::findFromCache(1)->name);
            TestEntity::findFromCache(1)->increment('amount', 3);
            self::assertSame(4, (int) TestEntity::findFromCache(1)->amount);
            TestEntity::findFromCache(1)->decrement('amount');
            self::assertSame(3, (int) TestEntity::findFromCache(1)->amount);
            TestEntity::query(true)->where('id', 1)->delete();
            self::assertNull(TestEntity::findFromCache(1));
        });
    }
    public function testTransactionBypassesCacheRollbackAndCommitRefreshState(): void
    {
        RequestScope::run(function (): void {
            self::assertSame('one', TestEntity::findFromCache(1)->name);
            $connection = (new TestEntity())->getConnection();
            $connection->beginTransaction();
            TestEntity::query(true)->where('id', 1)->update(['name' => 'uncommitted']);
            self::assertSame('uncommitted', TestEntity::findFromCache(1)->name);
            $connection->rollBack();
            self::assertSame('one', TestEntity::findFromCache(1)->name);
            $connection->beginTransaction();
            TestEntity::query(true)->where('id', 1)->update(['name' => 'outer']);
            $connection->beginTransaction();
            TestEntity::query(true)->where('id', 1)->update(['name' => 'inner']);
            $connection->rollBack();
            self::assertSame('outer', TestEntity::findFromCache(1)->name);
            $connection->commit();
            self::assertSame('outer', TestEntity::findFromCache(1)->name);
        });
    }
    public function testTransactionWithoutReadStillInvalidatesPreTransactionSnapshot(): void
    {
        RequestScope::run(function (): void {
            TestEntity::findFromCache(1);
            $connection = (new TestEntity())->getConnection();
            $connection->transaction(static fn () => TestEntity::query(true)->where('id', 1)->update(['name' => 'committed']));
            self::assertSame('committed', TestEntity::findFromCache(1)->name);
        });
    }
    public function testRefreshAndFreshBypassOldSnapshots(): void
    {
        RequestScope::run(function (): void {
            $entity = TestEntity::findFromCache(1);
            Fixture::pdo()->exec("UPDATE mc_entities SET name='external' WHERE id=1");
            self::assertSame('external', $entity->refresh()->name);
            self::assertSame('external', TestEntity::findFromCache(1)->name);
            Fixture::pdo()->exec("UPDATE mc_entities SET name='fresh' WHERE id=1");
            self::assertSame('fresh', $entity->fresh()->name);
            self::assertSame('fresh', TestEntity::findFromCache(1)->name);
        });
    }
    public function testContextOnlyAndNoRequestScopeDoNotLeak(): void
    {
        Fixture::container(['redis' => ['enabled' => false]]);
        RequestScope::run(function (): void {
            TestEntity::findFromCache(1);
            TestEntity::findFromCache(1);
            self::assertSame(1, Fixture::$selects);
        });
        TestEntity::findFromCache(1);
        TestEntity::findFromCache(1);
        self::assertSame(3, Fixture::$selects);
        RequestScope::run(static fn () => TestEntity::findFromCache(1));
        self::assertSame(4, Fixture::$selects);
    }
    public function testRedisOnlyAndBothDisabled(): void
    {
        Fixture::container(['context' => ['enabled' => false]]);
        TestEntity::findFromCache(1);
        TestEntity::findFromCache(1);
        self::assertSame(1, Fixture::$selects);
        Fixture::container(['context' => ['enabled' => false], 'redis' => ['enabled' => false]]);
        TestEntity::findFromCache(1);
        TestEntity::findFromCache(1);
        self::assertSame(2, Fixture::$selects);
    }
    public function testParallelReadsActuallyIssueOnlyOneDatabaseQuery(): void
    {
        Fixture::container(['redis' => ['enabled' => false]]);
        RequestScope::run(function (): void {
            $tasks = [];
            for ($i = 0; $i < 6; ++$i) {
                $tasks[] = RequestScope::spawn(static fn () => TestEntity::findFromCache(1));
            }
            foreach ($tasks as $task) {
                self::assertSame('one', $task->await()->name);
            }
        });
        self::assertSame(1, Fixture::$selects);
    }
    public function testStringHandlerRoundTripsAndUsesRedisAcrossScopes(): void
    {
        Fixture::container([], RedisStringHandler::class);
        RequestScope::run(static fn () => TestEntity::findFromCache(2));
        RequestScope::run(function (): void {
            self::assertSame("\x00\xff\x01", TestEntity::findFromCache(2)->payload);
            self::assertSame(1, Fixture::$selects);
        });
    }
    public function testUnmanagedModelsUsingSnapshotHandlerDoNotHydrateNegativeMarkers(): void
    {
        Fixture::container(['models' => []]);
        self::assertNull(TestEntity::findFromCache(999));
        self::assertNull(TestEntity::findFromCache(999));
        self::assertCount(0, TestEntity::findManyFromCache([999]));
    }
    public function testHashReplacementRemovesOldFieldsAndPreservesNullAndTtl(): void
    {
        $container = ApplicationContext::getContainer();
        $handler = $container->make(SnapshotRedisHandler::class, ['config' => new \Hyperf\ModelCache\Config(['pool' => 'default'], 'default')]);
        $key = 'mc_integration:handler:snapshot';
        $handler->set($key, ['id' => 1, 'removed' => 'x'], 20);
        $handler->set($key, ['id' => 1, 'nullable' => null, 'bytes' => "\0\xff"], 15);
        self::assertSame(['id' => 1, 'nullable' => null, 'bytes' => "\0\xff"], $handler->get($key));
        self::assertFalse(Fixture::redis()->hExists($key, 'removed'));
        self::assertGreaterThan(0, Fixture::redis()->ttl($key));
        Fixture::redis()->del($key);
    }
    public function testTableCleanupIncludesNegativeCacheAndPreservesOtherProjects(): void
    {
        TestEntity::findFromCache(1);
        TestEntity::findFromCache(9999);
        $redis = Fixture::redis();
        $redis->set('{mc:other_project:m:mc_entities}:id:1', 'keep');
        self::assertSame(2, $this->clearTableCache());
        self::assertSame('keep', $redis->get('{mc:other_project:m:mc_entities}:id:1'));
        self::assertSame(0, $this->clearTableCache());
        $redis->del('{mc:other_project:m:mc_entities}:id:1');
    }
    public function testTableCleanupRejectsUnsafeManifestBeforeDeletingAnything(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RedisTableCacheInvalidator())->invalidate(['version' => 1, 'tables' => [['pool' => 'default', 'template' => '*%s%s%s%s', 'prefix' => 'mc_integration', 'table' => 'mc_entities', 'primary_key' => 'id']]], ['default' => Fixture::redis()], true);
    }
    public function testCompleteCacheRejectsDefaultValueSynthesis(): void
    {
        $this->expectException(\LogicException::class);
        $container = Fixture::container();
        $container->get(ConfigInterface::class)->set('databases.default.cache.use_default_value', true);
        TestEntity::findFromCache(1);
    }
    public function testGlobalScopeNeverReceivesUnfilteredSharedRedisRow(): void
    {
        Fixture::container(['models' => [TestEntity::class, \HyperfTest\ModelCache\Support\TenantEntity::class]]);
        TestEntity::findFromCache(2); // Full Owner row in the shared table cache.
        RequestScope::run(function (): void {
            self::assertNull(\HyperfTest\ModelCache\Support\TenantEntity::findFromCache(2));
            self::assertSame('one', \HyperfTest\ModelCache\Support\TenantEntity::findFromCache(1)->name);
        });
    }
    public function testExplicitTenantScopeSeparatesRequestEntriesAndBypassesUnscopedRedis(): void
    {
        Fixture::container(['models' => [\HyperfTest\ModelCache\Support\TenantEntity::class], 'scope_provider' => \HyperfTest\ModelCache\Support\TenantScopeProvider::class]);
        RequestScope::run(function (): void {
            \Hyperf\Context\Context::set('mc_test_tenant', 1);
            self::assertSame('one', \HyperfTest\ModelCache\Support\TenantEntity::findFromCache(1)->name);
            \Hyperf\Context\Context::set('mc_test_tenant', 2);
            self::assertNull(\HyperfTest\ModelCache\Support\TenantEntity::findFromCache(1));
            \Hyperf\Context\Context::set('mc_test_tenant', 1);
            self::assertSame('one', \HyperfTest\ModelCache\Support\TenantEntity::findFromCache(1)->name);
            self::assertSame(2, Fixture::$selects);
        });
        \Hyperf\Context\Context::destroy('mc_test_tenant');
    }
}
