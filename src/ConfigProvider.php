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

use Hyperf\ModelCache\Listener\DeleteCacheInTransactionListener;
use Hyperf\ModelCache\Listener\DeleteCacheListener;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'listeners' => [
                \Hyperf\ModelCache\Listener\RequestCacheListener::class,
                DeleteCacheListener::class,
                DeleteCacheInTransactionListener::class,
            ],
            'publish' => [
                [
                    'id' => 'model-cache-runtime',
                    'description' => 'Request entity cache and complete row loading.',
                    'source' => __DIR__ . '/../publish/model_cache.php',
                    'destination' => BASE_PATH . '/config/autoload/model_cache.php',
                ],
                [
                    'id' => 'config',
                    'description' => 'The config for database with model-cache.',
                    'source' => __DIR__ . '/../publish/databases.php',
                    'destination' => BASE_PATH . '/config/autoload/databases.php',
                ],
            ],
        ];
    }
}
