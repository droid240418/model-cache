<?php

declare(strict_types=1);

defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';
date_default_timezone_set('UTC');
if ($file = getenv('MODEL_CACHE_TEST_CONFIG')) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        [$name, $value] = explode('=', $line, 2);
        if ($name === 'MYSQL_ROOT_PASSWORD') {
            putenv('MC_TEST_MYSQL_PASSWORD=' . $value);
        }
    }
}
// The split upstream repository referenced an internal monorepo test base.
class_alias(\Hyperf\DbConnection\Model\Model::class, 'HyperfTest\\Database\\Stubs\\Model\\Model');
if (getenv('MODEL_CACHE_INTEGRATION') === '1') {
    \HyperfTest\ModelCache\Support\Fixture::seed();
}
