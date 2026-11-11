<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Integration;

use Hyperf\ModelCache\Invalidation\RedisTableCacheInvalidator;
use PHPUnit\Framework\TestCase;

final class ClusterTest extends TestCase
{
    public function testCleanupScansEveryMasterAndPreservesAnotherProject(): void
    {
        if (! $seeds = getenv('MC_TEST_CLUSTER_SEEDS')) {
            $this->markTestSkipped('Explicit isolated Redis Cluster seeds required.');
        }
        $redis = new \RedisCluster(null, explode(',', $seeds), 2.0, 2.0);
        self::assertCount(3, $redis->_masters());
        $keys = [];
        for ($i = 0; $i < 30; ++$i) {
            $keys[] = $key = 'mc:mc_cluster_test:m:entities:id:' . $i;
            $redis->set($key, $i % 2 ? 'row' : 'missing');
        }
        $other = 'mc:another_project:m:entities:id:1';
        $redis->set($other, 'keep');
        $manifest = ['version' => 1, 'tables' => [['pool' => 'cluster', 'template' => 'mc:%s:m:%s:%s:%s', 'prefix' => 'mc_cluster_test', 'table' => 'entities', 'primary_key' => 'id']]];
        try {
            $invalidator = new RedisTableCacheInvalidator(2);
            self::assertSame(30, $invalidator->invalidate($manifest, ['cluster' => $redis], true));
            foreach ($keys as $key) {
                self::assertFalse($redis->get($key));
            }
            self::assertSame('keep', $redis->get($other));
            self::assertSame(0, $invalidator->invalidate($manifest, ['cluster' => $redis], true));
        } finally {
            foreach ($keys as $key) {
                $redis->del($key);
            } $redis->del($other);
        }
    }
}
