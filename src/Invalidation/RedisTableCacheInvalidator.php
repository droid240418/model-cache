<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Invalidation;

use Hyperf\ModelCache\Context\RequestScope;

/** Deployment-facing cleanup: no SQL, no KEYS/FLUSH, no discovery from mutable business models. */
final class RedisTableCacheInvalidator
{
    public function __construct(private readonly int $batchSize = 200)
    {
        if ($batchSize < 1 || $batchSize > 10000) {
            throw new \InvalidArgumentException('Invalid SCAN batch size.');
        }
    }
    /** @param array<string, \Redis|\RedisCluster> $clients Explicit deployment pool/database mappings. */
    public function invalidate(array $manifest, array $clients, bool $quiescent = false): int
    {
        if (! $quiescent) {
            throw new \LogicException('Drain affected requests and writers before table cache cleanup.');
        }
        if (($manifest['version'] ?? null) !== 1 || ! is_array($manifest['tables'] ?? null)) {
            throw new \InvalidArgumentException('Unsupported cache cleanup manifest.');
        }
        $targets = [];
        foreach ($manifest['tables'] as $target) {
            foreach (['pool', 'template', 'prefix', 'table', 'primary_key'] as $name) {
                if (! is_string($target[$name] ?? null) || $target[$name] === '') {
                    throw new \InvalidArgumentException('Incomplete cache cleanup target: ' . $name);
                }
            }
            $template = $target['template'];
            $literal = str_replace('%s', '', $template);
            if (substr_count($template, '%s') !== 4 || strpbrk($literal, '%*?[]\\') !== false) {
                throw new \InvalidArgumentException('Unsafe or unsupported cache key template.');
            }
            // A fixed project prefix and explicit entity components prevent expanding the deletion domain.
            foreach (['prefix', 'table', 'primary_key'] as $name) {
                if (! preg_match('/\A[A-Za-z0-9_.:-]+\z/', $target[$name])) {
                    throw new \InvalidArgumentException('Unsafe cache target identifier.');
                }
            }
            $client = $clients[$target['pool']] ?? null;
            if (! $client instanceof \Redis && ! $client instanceof \RedisCluster) {
                throw new \InvalidArgumentException('Cleanup needs a native Redis/RedisCluster client for every pool.');
            }
            $pattern = sprintf($template, $target['prefix'], $target['table'], $target['primary_key'], '*');
            $nodes = $client instanceof \RedisCluster ? $client->_masters() : [null];
            if (! $nodes) {
                throw new \RuntimeException('Redis Cluster master discovery failed.');
            }
            $targets[] = [$client, $pattern, $nodes];
        }
        RequestScope::invalidate();
        $deleted = 0;
        foreach ($targets as [$client, $pattern, $nodes]) {
            foreach ($nodes as $node) {
                $cursor = null;
                do {
                    $keys = $client instanceof \RedisCluster
                        ? $client->scan($cursor, $node, $pattern, $this->batchSize)
                        : $client->scan($cursor, $pattern, $this->batchSize);
                    foreach ($keys ?: [] as $key) {
                        try {
                            $count = $this->unlink($client, $key);
                        } catch (\RedisException $error) {
                            if (! str_contains(strtolower($error->getMessage()), 'unknown command')) {
                                throw $error;
                            }
                            $count = $client->del($key);
                        }
                        if ($count === false) {
                            throw new \RuntimeException('Redis cache deletion failed.');
                        }
                        $deleted += $count;
                    }
                } while ((int) $cursor !== 0);
            }
        }
        return $deleted;
    }
    /** @throws \RedisException Redis can reject UNLINK on older servers. */
    private function unlink(\Redis|\RedisCluster $client, string $key): int
    {
        $result = $client->unlink($key);
        if ($result === false) {
            throw new \RedisException($client->getLastError() ?: 'Redis UNLINK failed.');
        }
        return $result;
    }
}
