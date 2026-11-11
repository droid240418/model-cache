<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Hyperf\ModelCache;

use DateInterval;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\Model\Collection;
use Hyperf\Database\Model\Model;
use Hyperf\DbConnection\Collector\TableCollector;
use Hyperf\ModelCache\Handler\DefaultValueInterface;
use Hyperf\ModelCache\Handler\HandlerInterface;
use Hyperf\ModelCache\Handler\RedisHandler;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

use function Hyperf\Support\make;

class Manager
{
    private ?Runtime $runtime = null;
    /**
     * @var HandlerInterface[]
     */
    protected array $handlers = [];

    protected LoggerInterface $logger;

    protected TableCollector $collector;

    public function __construct(protected ContainerInterface $container)
    {
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->collector = $container->get(TableCollector::class);

        $config = $container->get(ConfigInterface::class);
        $options = $config->get('model_cache', []);
        foreach (['context', 'redis'] as $layer) {
            if (isset($options[$layer]['enabled']) && ! is_bool($options[$layer]['enabled'])) {
                throw new InvalidArgumentException('model_cache.' . $layer . '.enabled must be a boolean.');
            }
        }
        foreach ($options['models'] ?? [] as $class) {
            if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                throw new InvalidArgumentException('Cache models must be ORM model class names.');
            }
        }
        if ($options['models'] ?? []) {
            $metadata = $container->get($options['metadata_provider'] ?? \Hyperf\ModelCache\Metadata\MySqlMetadataProvider::class);
            $scopes = $container->get($options['scope_provider'] ?? \Hyperf\ModelCache\Metadata\SafeScopeProvider::class);
            $this->runtime = new Runtime($options, new CompleteRowLoader($metadata), $scopes);
        }
        if (! $config->has('databases')) {
            throw new InvalidArgumentException('config databases is not exist!');
        }

        foreach ($config->get('databases') as $key => $item) {
            if (($options['redis']['enabled'] ?? true) === false || ($item['driver'] ?? 'mysql') === 'manticore') {
                continue;
            }
            $handlerClass = $item['cache']['handler'] ?? RedisHandler::class;
            $config = new Config($item['cache'] ?? [], $key);

            /** @var HandlerInterface $handler */
            $handler = make($handlerClass, ['config' => $config]);

            $this->handlers[$key] = $handler;
        }
    }

    /**
     * Fetch a model from cache.
     * @param int|string $id
     * @param class-string<Model> $class
     */
    public function findFromCache($id, string $class): ?Model
    {
        if ($this->runtime?->supports($class)) {
            $instance = new $class();
            $name = \Hyperf\ModelCache\Metadata\ModelConnection::name($instance);
            $data = $this->runtime->snapshots($instance, [$id], $this->handlers[$name] ?? null);
            return $data[(string) $id] === null ? null : $instance->newFromBuilder($data[(string) $id]);
        }
        $instance = new $class();
        $name = $instance->getConnectionName();
        $primaryKey = $instance->getKeyName();

        if ($handler = $this->handlers[$name] ?? null) {
            $key = $this->getCacheKey($id, $instance, $handler->getConfig());
            $data = $handler->get($key);
            if ($data) {
                return $instance->newFromBuilder(
                    $this->getAttributes($handler->getConfig(), $instance, $data)
                );
            }

            // Fetch it from database, because it not exists in cache handler.
            if ($data === null) {
                $model = $instance->newQuery()->where($primaryKey, '=', $id)->first();
                if ($model) {
                    $ttl = $this->getCacheTTL($instance, $handler);
                    $handler->set($key, $this->formatModel($model), $ttl);
                } else {
                    $ttl = $handler->getConfig()->getEmptyModelTtl();
                    $handler->set($key, $this->defaultValue($handler, $id), $ttl);
                }
                return $model;
            }

            // It not exists in cache handler and database.
            return null;
        }

        $this->logger->alert('Cache handler not exist, fetch data from database.');
        return $instance->newQuery()->where($primaryKey, '=', $id)->first();
    }

    /**
     * Fetch many models from cache.
     * @param array<int|string> $ids
     * @param class-string<Model> $class
     */
    public function findManyFromCache(array $ids, string $class): Collection
    {
        if ($this->runtime?->supports($class)) {
            $instance = new $class();
            if (! $ids) {
                return $instance->newCollection();
            }
            $name = \Hyperf\ModelCache\Metadata\ModelConnection::name($instance);
            $data = $this->runtime->snapshots($instance, $ids, $this->handlers[$name] ?? null);
            $models = [];
            foreach (array_unique(array_map(strval(...), $ids)) as $id) {
                if ($data[$id] !== null) {
                    $models[] = $instance->newFromBuilder($data[$id]);
                }
            }
            return $instance->newCollection($models);
        }
        if (count($ids) === 0) {
            return new Collection([]);
        }

        $instance = new $class();
        $name = $instance->getConnectionName();
        $primaryKey = $instance->getKeyName();

        if ($handler = $this->handlers[$name] ?? null) {
            $keys = [];
            foreach ($ids as $id) {
                $keys[] = $this->getCacheKey($id, $instance, $handler->getConfig());
            }
            $data = $handler->getMultiple($keys);
            $items = [];
            $fetchIds = [];
            foreach ($data as $item) {
                if ($handler instanceof DefaultValueInterface && $handler->isDefaultValue($item)) {
                    $fetchIds[] = $handler->getPrimaryValue($item);
                    continue;
                }

                if (isset($item[$primaryKey])) {
                    if ($handler instanceof DefaultValueInterface) {
                        $item = $handler->clearDefaultValue($item);
                    }
                    $items[] = $item;
                    $fetchIds[] = $item[$primaryKey];
                }
            }

            // Get ids that not exist in cache handler.
            $targetIds = array_diff($ids, $fetchIds);
            if ($targetIds) {
                /** @var Collection<int, Model> $models */
                $models = $instance->newQuery()->whereIn($primaryKey, $targetIds)->get();
                $dictionary = $models->getDictionary();
                $ttl = $this->getCacheTTL($instance, $handler);
                $emptyTtl = $handler->getConfig()->getEmptyModelTtl();
                foreach ($targetIds as $id) {
                    $key = $this->getCacheKey($id, $instance, $handler->getConfig());
                    if ($model = $dictionary[$id] ?? null) {
                        $handler->set($key, $this->formatModel($model), $ttl);
                    } else {
                        $handler->set($key, $this->defaultValue($handler, $id), $emptyTtl);
                    }
                }

                $items = array_merge($items, $this->formatModels($models));
            }
            $map = [];
            foreach ($items as $item) {
                $map[$item[$primaryKey]] = $this->getAttributes($handler->getConfig(), $instance, $item);
            }

            $result = [];
            foreach ($ids as $id) {
                if (isset($map[$id])) {
                    $result[] = $map[$id];
                }
            }

            return $instance->newQuery()->hydrate($result);
        }

        $this->logger->alert('Cache handler not exist, fetch data from database.');
        // @phpstan-ignore-next-line
        return $instance->newQuery()->whereIn($primaryKey, $ids)->get();
    }

    /**
     * Destroy the models for the given IDs from cache.
     * @param class-string<Model> $class
     */
    public function destroy(iterable $ids, string $class): bool
    {
        \Hyperf\ModelCache\Context\RequestScope::invalidate();
        if ($this->runtime?->supports($class)) {
            $instance = new $class();
            $ids = is_array($ids) ? $ids : iterator_to_array($ids);
            if ($instance->getConnection()->transactionLevel() > 0) {
                TransactionState::defer(\Hyperf\ModelCache\Metadata\ModelConnection::name($instance), $class, $ids);
                return true;
            }
            $handler = $this->handlers[\Hyperf\ModelCache\Metadata\ModelConnection::name($instance)] ?? null;
            if (! $handler || ! $ids) {
                return true;
            }
            $keys = array_map(fn ($id) => $this->runtime->redisKey($instance, (string) $id, $handler->getConfig()), $ids);
            // DEL=0 means already absent; cleanup is idempotently successful.
            $handler->deleteMultiple($keys);
            return true;
        }
        $instance = new $class();
        $name = $instance->getConnectionName();

        if ($handler = $this->handlers[$name] ?? null) {
            $keys = [];
            foreach ($ids as $id) {
                $keys[] = $this->getCacheKey($id, $instance, $handler->getConfig());
            }

            return $handler->deleteMultiple($keys);
        }

        return false;
    }

    /**
     * Increment a column's value by a given amount.
     * @param mixed $id
     * @param mixed $column
     * @param mixed $amount
     * @param class-string<Model> $class
     */
    public function increment($id, $column, $amount, string $class): bool
    {
        if ($this->runtime?->supports($class)) {
            return $this->destroy([$id], $class);
        }
        $instance = new $class();
        $name = $instance->getConnectionName();

        if ($handler = $this->handlers[$name] ?? null) {
            $key = $this->getCacheKey($id, $instance, $handler->getConfig());
            if ($handler->has($key)) {
                return $handler->incr($key, $column, $amount);
            }

            return false;
        }

        $this->logger->alert('Cache handler not exist, increment failed.');
        return false;
    }

    public function formatModel(Model $model): array
    {
        return $model->getAttributes();
    }

    public function formatModels($models): array
    {
        $result = [];
        foreach ($models as $model) {
            $result[] = $this->formatModel($model);
        }

        return $result;
    }

    protected function getCacheTTL(Model $instance, HandlerInterface $handler): DateInterval|int
    {
        if ($instance instanceof CacheableInterface) {
            return $instance->getCacheTTL() ?? $handler->getConfig()->getTtl();
        }
        return $handler->getConfig()->getTtl();
    }

    /**
     * @param int|string $id
     */
    protected function getCacheKey($id, Model $model, Config $config): string
    {
        // mc:$prefix:m:$model:$pk:$id
        return sprintf(
            $config->getCacheKey(),
            $config->getPrefix(),
            $model->getTable(),
            $model->getKeyName(),
            $id
        );
    }

    protected function getAttributes(Config $config, Model $model, array $data)
    {
        if (! $config->isUseDefaultValue()) {
            return $data;
        }

        $connection = $model->getConnectionName();
        $defaultData = $this->collector->getDefaultValue(
            $connection,
            $this->getPrefix($connection) . $model->getTable()
        );
        return array_replace($defaultData, $data);
    }

    protected function getPrefix(string $connection): string
    {
        return (string) $this->container->get(ConfigInterface::class)->get('databases.' . $connection . '.prefix');
    }

    protected function defaultValue(mixed $handler, mixed $primaryValue): array
    {
        if ($handler instanceof \Hyperf\ModelCache\Handler\CompleteSnapshotHandler) {
            return [];
        }
        if ($handler instanceof DefaultValueInterface) {
            return $handler->defaultValue($primaryValue);
        }

        return [];
    }
}
