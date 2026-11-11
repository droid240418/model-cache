<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Metadata;

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Model\Model;

/** ConnectionInterface deliberately omits config/getName; use the configured resolver contract. */
final class ModelConnection
{
    public static function name(Model $model): string
    {
        $name = $model->getConnectionName();
        return $name ?: ApplicationContext::getContainer()->get(ConnectionResolverInterface::class)->getDefaultConnection();
    }
    public static function config(Model $model): array
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class)->get('databases.' . self::name($model));
        if (! is_array($config)) {
            throw new \LogicException('Model connection configuration is missing.');
        }
        return $config;
    }
}
