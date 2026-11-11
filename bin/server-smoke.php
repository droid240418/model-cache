<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tests/bootstrap.php';

use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\HttpMessage\Server\Request;
use Hyperf\HttpMessage\Server\Response;
use Hyperf\ModelCache\Context\RequestScope;
use Hyperf\ModelCache\Middleware\RequestCacheMiddleware;
use HyperfTest\ModelCache\Support\Fixture;
use HyperfTest\ModelCache\Support\TestEntity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

if (getenv('MODEL_CACHE_INTEGRATION') !== '1') {
    throw new RuntimeException('Isolated test services required.');
}
$mode = ($argv[1] ?? '') === 'process' ? SWOOLE_PROCESS : SWOOLE_BASE;
$port = (int) ($argv[2] ?? 19501);
(static function (): void {
    $pdo = Fixture::pdo();
    $pdo->exec("DELETE FROM mc_entities");
    $pdo->exec("INSERT INTO mc_entities(id,name,secret) VALUES(1,'smoke','invisible')");
})();
$server = new Swoole\Http\Server('127.0.0.1', $port, $mode);
$server->set(['worker_num' => 2, 'log_level' => SWOOLE_LOG_WARNING, 'hook_flags' => SWOOLE_HOOK_ALL]);
$server->on('workerStart', static fn () => Fixture::container(['redis' => ['enabled' => false]]));
$server->on('request', static function ($request, $response): void {
    try {
        $before = Fixture::$selects;
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $tasks = [];
                for ($i = 0; $i < 6; ++$i) {
                    $tasks[] = RequestScope::spawn(static fn () => TestEntity::findFromCache(1));
                }
                foreach ($tasks as $task) {
                    $entity = $task->await();
                    if ($entity->name !== 'smoke' || $entity->secret !== 'invisible') {
                        throw new RuntimeException('Bad cached snapshot.');
                    }
                }
                return (new Response())->withContent(json_encode(RequestScope::current()->stats(), JSON_THROW_ON_ERROR));
            }
        };
        $middleware = new RequestCacheMiddleware(ApplicationContext::getContainer()->get(ConfigInterface::class));
        $result = $middleware->process(new Request('GET', '/'), $handler);
        $data = json_decode((string) $result->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $data['queries'] = Fixture::$selects - $before;
        $data['released'] = RequestScope::current() === null;
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($data, JSON_THROW_ON_ERROR));
    } catch (Throwable $error) {
        $response->status(500);
        $response->end($error->getMessage());
    }
});
$server->start();
