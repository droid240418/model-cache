<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Support;

use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Database\ConnectionInterface;
use Hyperf\Database\ConnectionResolverInterface;
use Hyperf\Database\Events\QueryExecuted;
use Hyperf\Database\Model\Model;
use Hyperf\Database\MySqlConnection;
use Hyperf\DbConnection\Collector\TableCollector;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\Event\EventDispatcher;
use Hyperf\Event\ListenerProvider;
use Hyperf\ModelCache\ConfigProvider;
use Hyperf\ModelCache\Handler\SnapshotRedisHandler;
use Hyperf\ModelCache\Manager;
use HyperfTest\ModelCache\Stub\StdoutLogger;
use Psr\EventDispatcher\EventDispatcherInterface;

final class Fixture
{
    public static int $selects = 0;
    public static function db(): array
    {
        return ['driver' => 'mysql', 'host' => getenv('MC_TEST_MYSQL_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('MC_TEST_MYSQL_PORT') ?: 13306),
            'database' => 'model_cache_test', 'username' => 'root', 'password' => getenv('MC_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_ai_ci', 'prefix' => '', 'name' => 'default'];
    }
    public static function pdo(): \PDO
    {
        $db = self::db();
        return new \PDO("mysql:host={$db['host']};port={$db['port']};dbname=model_cache_test;charset=utf8mb4", 'root', $db['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }
    public static function seed(): void
    {
        $pdo = self::pdo();
        $targets = [];
        foreach (['user', 'book', 'images', 'user_ext'] as $table) {
            $targets[] = ['pool' => 'default', 'template' => '{mc:%s:m:%s}:%s:%s', 'prefix' => 'default', 'table' => $table, 'primary_key' => 'id'];
        }
        (new \Hyperf\ModelCache\Invalidation\RedisTableCacheInvalidator())->invalidate(['version' => 1, 'tables' => $targets], ['default' => self::redis()], true);
        $schema = [
            'user' => 'id BIGINT PRIMARY KEY AUTO_INCREMENT,name VARCHAR(100) NOT NULL,gender INT NOT NULL DEFAULT 0,created_at DATETIME NULL,updated_at DATETIME NULL',
            'book' => 'id BIGINT PRIMARY KEY AUTO_INCREMENT,user_id BIGINT,title VARCHAR(200),created_at DATETIME NULL,updated_at DATETIME NULL',
            'images' => 'id BIGINT PRIMARY KEY AUTO_INCREMENT,url VARCHAR(255),imageable_id BIGINT,imageable_type VARCHAR(255),created_at DATETIME NULL,updated_at DATETIME NULL',
            'user_ext' => 'id BIGINT PRIMARY KEY AUTO_INCREMENT,count INT DEFAULT 0,float_num DOUBLE DEFAULT 0,str VARCHAR(255) DEFAULT \'\',json JSON NULL,created_at DATETIME NULL,updated_at DATETIME NULL',
            'mc_entities' => 'id BIGINT PRIMARY KEY,name VARCHAR(100) NOT NULL,amount INT DEFAULT 0,secret VARCHAR(100) INVISIBLE,payload VARBINARY(100) NULL,updated_at DATETIME NULL',
        ];
        foreach ($schema as $table => $columns) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `$table` ($columns)");
        }
        $pdo->exec("INSERT INTO user(id,name,gender,created_at,updated_at) VALUES(1,'one',1,'2020-01-01','2020-01-01'),(2,'two',0,'2020-01-01','2020-01-01'),(3,'three',1,'2020-01-01','2020-01-01') ON DUPLICATE KEY UPDATE created_at='2020-01-01',updated_at='2020-01-01'");
        $pdo->exec("INSERT INTO user_ext(id,count,float_num,str,json,created_at,updated_at) VALUES(1,1,1.1,'x','{\"id\":1}','2020-01-01','2020-01-01') ON DUPLICATE KEY UPDATE json='{\"id\":1}',created_at='2020-01-01',updated_at='2020-01-01'");
        $pdo->exec("INSERT IGNORE INTO book(id,user_id,title) VALUES(1,1,'one'),(2,2,'two')");
        $user = addslashes(\HyperfTest\ModelCache\Stub\UserModel::class);
        $book = addslashes(\HyperfTest\ModelCache\Stub\BookModel::class);
        $pdo->exec("INSERT IGNORE INTO images(id,url,imageable_id,imageable_type) VALUES(1,'one',1,'$user'),(2,'two',2,'$user'),(3,'book',1,'$book')");
    }
    public static function container(array $options = [], string $handler = SnapshotRedisHandler::class): Container
    {
        Context::destroy(self::class);
        $container = new Container(new DefinitionSource([]));
        ApplicationContext::setContainer($container);
        $config = new Config([
            'model_cache' => array_replace_recursive(['models' => [TestEntity::class], 'context' => ['enabled' => true], 'redis' => ['enabled' => true]], $options),
            'databases' => ['default' => self::db() + ['cache' => ['handler' => $handler, 'cache_key' => '{mc:%s:m:%s}:%s:%s', 'prefix' => 'mc_integration', 'ttl' => 60, 'empty_model_ttl' => 15]]],
            'redis' => ['default' => ['host' => '127.0.0.1', 'port' => (int) (getenv('MC_TEST_REDIS_PORT') ?: 16379), 'db' => 0, 'pool' => ['min_connections' => 0, 'max_connections' => 20]]],
        ]);
        $container->set(ConfigInterface::class, $config);
        $container->set(StdoutLoggerInterface::class, new StdoutLogger());
        $container->set(TableCollector::class, new TableCollector());
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $container->set(EventDispatcherInterface::class, $dispatcher);
        foreach ((new ConfigProvider())()['listeners'] as $listenerClass) {
            $listener = $container->get($listenerClass);
            foreach ($listener->listen() as $event) {
                $provider->on($event, [$listener, 'process']);
            }
        }
        $provider->on(QueryExecuted::class, static function (QueryExecuted $event): void {
            if (str_starts_with(strtolower($event->sql), 'select') && str_contains($event->sql, '`mc_entities`')) {
                ++self::$selects;
            }
        });
        $resolver = new class ($dispatcher) implements ConnectionResolverInterface {
            public function __construct(private EventDispatcherInterface $dispatcher)
            {
            }
            public function connection(?string $name = null): ConnectionInterface
            {
                return Context::getOrSet(Fixture::class, function (): MySqlConnection {
                    $db = Fixture::db();
                    $connection = new MySqlConnection(Fixture::pdo(), $db['database'], '', $db);
                    $connection->setEventDispatcher($this->dispatcher);
                    return $connection;
                });
            }
            public function getDefaultConnection(): string
            {
                return 'default';
            }
            public function setDefaultConnection(string $name): void
            {
            }
        };
        $container->set(ConnectionResolverInterface::class, $resolver);
        self::$selects = 0;
        return $container;
    }
    public static function redis(): \Redis
    {
        $redis = new \Redis();
        $redis->connect('127.0.0.1', (int) (getenv('MC_TEST_REDIS_PORT') ?: 16379));
        return $redis;
    }
}
