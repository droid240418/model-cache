<?php

declare(strict_types=1);

namespace Hyperf\ModelCache;

use Hyperf\Database\Model\Model;
use Hyperf\ModelCache\Context\RequestScope;
use Hyperf\ModelCache\Contract\CacheScopeProvider;
use Hyperf\ModelCache\Handler\HandlerInterface;

final class Runtime
{
    public function __construct(
        private array $options,
        private CompleteRowLoader $loader,
        private CacheScopeProvider $scopes,
    ) {
    }
    public function supports(string $class): bool
    {
        return in_array($class, $this->options['models'] ?? [], true);
    }
    public function enabledRedis(): bool
    {
        return $this->options['redis']['enabled'] ?? true;
    }
    public function snapshots(Model $model, array $ids, ?HandlerInterface $handler): array
    {
        $scope = $this->scopes->scope($model);
        // Scoped rows are safe in the request bag but not in an unscoped shared Redis key.
        if ($scope !== '') {
            $handler = null;
        }
        if ($handler && ! $handler instanceof \Hyperf\ModelCache\Handler\CompleteSnapshotHandler) {
            throw new \LogicException('Enhanced Redis cache requires a CompleteSnapshotHandler.');
        }
        $ids = array_values(array_unique(array_map(strval(...), $ids)));
        if ($scope === null || $model->getConnection()->transactionLevel() > 0) {
            RequestScope::invalidate();
            return $this->loader->loadMany($model, $ids);
        }
        $identity = [\Hyperf\ModelCache\Metadata\ModelConnection::config($model),
            $model->getTable(), $model::class, $model->getKeyName(), $scope];
        $prefix = hash('sha256', serialize($identity));
        $keys = [];
        foreach ($ids as $id) {
            $keys[$prefix . ':' . $id] = $id;
        }
        $state = ($this->options['context']['enabled'] ?? false) ? RequestScope::current() : null;
        $load = function (array $requested) use ($keys, $model, $scope, $handler, $state): array {
            $revision = $state?->revision();
            $result = [];
            $missing = [];
            $columns = $this->loader->columns($model);
            if ($handler && $handler->getConfig()->isUseDefaultValue()) {
                throw new \LogicException('Enhanced entity cache rejects use_default_value.');
            }
            foreach ($requested as $key) {
                $id = $keys[$key];
                $value = $handler?->get($this->redisKey($model, $id, $handler->getConfig(), $scope));
                if ($value === []) {
                    $result[$key] = null;
                } elseif (is_array($value) && ! array_diff($columns, array_keys($value)) && ! array_diff(array_keys($value), $columns)) {
                    $result[$key] = $value;
                } else {
                    $missing[$key] = $id;
                }
            }
            if ($missing) {
                $loaded = $this->loader->loadMany($model, array_values($missing));
                foreach ($missing as $key => $id) {
                    $snapshot = $result[$key] = $loaded[$id];
                    // Do not publish a pre-invalidation load into Redis or the request scope.
                    if ($handler && (! $state || $revision === $state->revision())) {
                        $config = $handler->getConfig();
                        $ttl = $snapshot === null ? $config->getEmptyModelTtl() : ($model instanceof CacheableInterface ? ($model->getCacheTTL() ?? $config->getTtl()) : $config->getTtl());
                        if (! $handler->set($this->redisKey($model, $id, $config, $scope), $snapshot ?? [], $ttl)) {
                            throw new \RuntimeException('Entity cache snapshot write failed.');
                        }
                    }
                }
            }
            return $result;
        };
        $values = $state ? $state->rememberMany(array_keys($keys), $load) : $load(array_keys($keys));
        $result = [];
        foreach ($keys as $key => $id) {
            $result[$id] = $values[$key];
        }
        return $result;
    }
    public function redisKey(Model $model, string $id, Config $config, ?string $scope = null): string
    {
        $scope ??= $this->scopes->scope($model);
        $base = sprintf($config->getCacheKey(), $config->getPrefix(), $model->getTable(), $model->getKeyName(), $id);
        return $scope === null || $scope === '' ? $base : $base . ':scope:' . hash('sha256', $model::class . ':' . $scope);
    }
}
