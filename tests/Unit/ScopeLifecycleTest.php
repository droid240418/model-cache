<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Unit;

use Hyperf\Engine\Channel;
use Hyperf\ModelCache\Context\RequestScope;
use Hyperf\ModelCache\Middleware\RequestCacheMiddleware;
use Hyperf\Config\Config;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ScopeLifecycleTest extends TestCase
{
    public function testFailedOwnerWakesWaitersWhichCanRetry(): void
    {
        $calls = 0;
        RequestScope::run(function () use (&$calls): void {
            $ready = new Channel(1);
            $owner = RequestScope::spawn(function () use ($ready, &$calls): bool {
                try {
                    RequestScope::current()->remember('x', function () use ($ready, &$calls): array {
                        ++$calls;
                        $ready->push(true);
                        \Swoole\Coroutine::sleep(0.01);
                        throw new \RuntimeException('owner failed');
                    });
                } catch (\RuntimeException) {
                    return true;
                }
                return false;
            });
            $ready->pop();
            $waiter = RequestScope::spawn(function () use (&$calls): ?array {
                return RequestScope::current()->remember('x', function () use (&$calls): array {
                    ++$calls;
                    return ['ok' => true];
                });
            });
            self::assertTrue($owner->await());
            self::assertSame(['ok' => true], $waiter->await());
        });
        self::assertSame(2, $calls);
    }
    public function testWaitingTimeoutDoesNotPoisonSuccessfulLoad(): void
    {
        RequestScope::run(function (): void {
            $started = new Channel(1);
            $owner = RequestScope::spawn(fn () => RequestScope::current()->remember('x', function () use ($started): array {
                $started->push(true);
                \Swoole\Coroutine::sleep(0.03);
                return ['ok' => 1];
            }));
            $started->pop();
            try {
                RequestScope::current()->remember('x', static fn () => []);
                self::fail('Expected timeout');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Timed out', $error->getMessage());
            }
            self::assertSame(['ok' => 1], $owner->await());
            self::assertSame(['ok' => 1], RequestScope::current()->remember('x', static fn () => []));
        }, 100, 0.005);
    }
    public function testTaskTimeoutCancelsChildAndCleansScope(): void
    {
        try {
            RequestScope::run(static fn () => RequestScope::spawn(static function (): void {
                \Swoole\Coroutine::sleep(1);
            }, 0.005)->await());
            self::fail('Expected task timeout');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('timed out', $error->getMessage());
        }
        self::assertNull(RequestScope::current());
    }
    public function testOverlappingBatchesCannotDeadlockAndKeepReadyEntries(): void
    {
        RequestScope::run(function (): void {
            $first = RequestScope::spawn(fn () => RequestScope::current()->rememberMany(['a', 'b'], static function (array $keys): array {
                \Swoole\Coroutine::sleep(0.01);
                return array_fill_keys($keys, ['source' => 'first']);
            }));
            $second = RequestScope::spawn(fn () => RequestScope::current()->rememberMany(['b', 'c'], static fn (array $keys): array => array_fill_keys($keys, ['source' => 'second'])));
            self::assertSame(['source' => 'first'], $first->await()['b']);
            self::assertSame(['source' => 'first'], $second->await()['b']);
            self::assertSame(['source' => 'second'], $second->await()['c']);
        });
    }
    public function testEntryAtLimitStillHitsAndStatisticsContainNoEntityData(): void
    {
        RequestScope::run(function (): void {
            RequestScope::current()->remember('x', static fn () => ['secret' => 'hidden']);
            self::assertSame(['secret' => 'hidden'], RequestScope::current()->remember('x', static fn () => throw new \Exception('cache miss')));
            self::assertSame(1, RequestScope::current()->stats()['hits']);
            self::assertStringNotContainsString('hidden', json_encode(RequestScope::current()->stats()));
        }, 1);
    }
    public function testHttpMiddlewareReleasesScopeOnException(): void
    {
        $middleware = new RequestCacheMiddleware(new Config(['model_cache' => ['context' => ['enabled' => true]]]));
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function (): ResponseInterface {
            self::assertNotNull(RequestScope::current());
            throw new \RuntimeException('http failure');
        });
        try {
            $middleware->process($this->createMock(ServerRequestInterface::class), $handler);
        } catch (\RuntimeException $error) {
            self::assertSame('http failure', $error->getMessage());
        }
        self::assertNull(RequestScope::current());
    }
}
