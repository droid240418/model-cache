<?php

declare(strict_types=1);

namespace Hyperf\ModelCache;

use Hyperf\Database\Model\Model;
use Hyperf\ModelCache\Contract\CompleteRowMetadataProvider;

final class CompleteRowLoader
{
    public function __construct(private CompleteRowMetadataProvider $metadata)
    {
    }
    public function columns(Model $model): array
    {
        return $this->metadata->columns($model);
    }
    public function loadMany(Model $model, array $ids): array
    {
        $columns = $this->columns($model);
        $models = $model->newQuery()->whereIn($model->getKeyName(), $ids)->get(array_map($model->qualifyColumn(...), $columns));
        $result = array_fill_keys(array_map(strval(...), $ids), null);
        foreach ($models as $entity) {
            $attributes = $entity->getRawOriginal();
            if (array_diff($columns, array_keys($attributes))) {
                throw new \LogicException('Incomplete row returned by model query.');
            }
            foreach ($attributes as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw new \LogicException('Only raw scalar/null attributes can be cached.');
                }
            }
            $result[(string) $entity->getKey()] = $attributes;
        }
        return $result;
    }
}
