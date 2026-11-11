<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Metadata;

use Hyperf\Database\Model\Model;
use Hyperf\ModelCache\Contract\CompleteRowMetadataProvider;

final class MySqlMetadataProvider implements CompleteRowMetadataProvider
{
    private array $cache = [];
    public function clear(): void
    {
        $this->cache = [];
    }
    public function columns(Model $model): array
    {
        $connection = $model->getConnection();
        $config = ModelConnection::config($model);
        if (($config['driver'] ?? null) !== 'mysql') {
            throw new \LogicException('Configure a complete-row metadata provider for this backend.');
        }
        $table = (string) ($config['prefix'] ?? '') . $model->getTable();
        $key = hash('sha256', serialize([$config, $table]));
        if (! isset($this->cache[$key])) {
            $rows = $connection->select('SELECT COLUMN_NAME AS column_name FROM information_schema.columns WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table], false);
            $columns = array_map(static fn ($row) => ((array) $row)['column_name'], $rows);
            if (! $columns || ! in_array($model->getKeyName(), $columns, true)) {
                throw new \LogicException('Complete row metadata is missing the entity primary key.');
            }
            $this->cache[$key] = $columns;
        }
        return $this->cache[$key];
    }
}
