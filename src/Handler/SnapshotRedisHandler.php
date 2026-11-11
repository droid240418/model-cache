<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Handler;

use Hyperf\Contract\Arrayable;

/** Redis Hash snapshot format; legacy hashes are treated as cold misses, never guessed. */
class SnapshotRedisHandler extends RedisHandler implements CompleteSnapshotHandler
{
    private const FORMAT = '__mc_snapshot_v1';
    public function get($key, $default = null): mixed
    {
        $data = $this->redis->hGetAll($key);
        if (($data[self::FORMAT] ?? null) !== '1') {
            return $default;
        }
        unset($data[self::FORMAT]);
        foreach ($data as &$value) {
            $encoded = $value;
            $value = @unserialize($encoded, ['allowed_classes' => false]);
            if (($value === false && $encoded !== 'b:0;') || (! is_scalar($value) && $value !== null)) {
                return $default;
            }
        }
        return $data;
    }
    public function set($key, $value, $ttl = null): bool
    {
        $data = $value instanceof Arrayable ? $value->toArray() : $value;
        if (! is_array($data)) {
            throw new \InvalidArgumentException('Snapshot must be an attribute array.');
        }
        $args = [$key, $ttl === null ? 0 : max(0, $this->secondsUntil($ttl)), self::FORMAT, '1'];
        foreach ($data as $field => $item) {
            if (! is_scalar($item) && $item !== null) {
                throw new \InvalidArgumentException('Snapshot values must be scalar/null.');
            }
            if ((string) $field === self::FORMAT) {
                throw new \InvalidArgumentException('Reserved snapshot field.');
            }
            $args[] = (string) $field;
            $args[] = serialize($item);
        }
        $script = "redis.call('DEL', KEYS[1]); for i=2,#ARGV,2 do redis.call('HSET',KEYS[1],ARGV[i],ARGV[i+1]); end; if tonumber(ARGV[1])>0 then redis.call('EXPIRE',KEYS[1],ARGV[1]); end; return 1";
        return (bool) $this->redis->eval($script, $args, 1);
    }
    public function getMultiple($keys, $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[] = $this->get($key, $default);
        }
        return $result;
    }
    public function incr($key, $column, $amount): bool
    {
        return $this->delete($key);
    }
}
