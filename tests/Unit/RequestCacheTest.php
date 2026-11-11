<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Unit;

use Hyperf\Engine\Channel;
use Hyperf\ModelCache\Context\RequestScope;
use PHPUnit\Framework\TestCase;

final class RequestCacheTest extends TestCase
{
    public function testSequentialReadsAndMissingRecordsAreMemoized(): void
    {
        $calls = 0;
        RequestScope::run(function () use (&$calls): void {
            $cache = RequestScope::current();
            $load = function () use (&$calls): ?array {
                ++$calls;
                return null;
            };
            self::assertNull($cache->remember('missing', $load));
            self::assertNull($cache->remember('missing', $load));
            $cache->remember('one', function () use (&$calls): array {
                ++$calls;
                return ['id' => 1];
            });
            self::assertSame(['id' => 1], $cache->remember('one', static fn () => throw new \Exception('not called')));
        });
        self::assertSame(2, $calls);
        self::assertNull(RequestScope::current());
    }
    public function testParallelTasksShareOneLoadAndAreJoined(): void
    {
        $calls = 0;
        RequestScope::run(function () use (&$calls): void {
            $tasks = [];
            for ($i = 0; $i < 8; ++$i) {
                $tasks[] = RequestScope::spawn(function () use (&$calls): array {
                    return RequestScope::current()->remember('one', function () use (&$calls): array {
                        ++$calls;
                        \Swoole\Coroutine::sleep(0.01);
                        return ['id' => 1];
                    });
                });
            }
            foreach ($tasks as $task) {
                self::assertSame(['id' => 1], $task->await());
            }
        });
        self::assertSame(1, $calls);
    }
    public function testInflightInvalidationDiscardsOldResult(): void
    {
        $calls = 0;
        RequestScope::run(function () use (&$calls): void {
            $started = new Channel(1);
            $task = RequestScope::spawn(function () use ($started, &$calls): array {
                return RequestScope::current()->remember('one', function () use ($started, &$calls): array {
                    $call = ++$calls;
                    if ($call === 1) {
                        $started->push(true);
                        \Swoole\Coroutine::sleep(0.02);
                    }
                    return ['revision' => $call];
                });
            });
            $started->pop();
            RequestScope::invalidate();
            self::assertSame(['revision' => 2], $task->await());
        });
        self::assertSame(2, $calls);
    }
    public function testExceptionsAreNotNegativeCached(): void
    {
        RequestScope::run(function (): void {
            try {
                RequestScope::current()->remember('x', static fn () => throw new \RuntimeException('fail'));
            } catch (\RuntimeException $error) {
                self::assertSame('fail', $error->getMessage());
            }
            self::assertSame(['ok' => true], RequestScope::current()->remember('x', static fn () => ['ok' => true]));
        });
    }
    public function testRecursiveLoadFailsInsteadOfDeadlocking(): void
    {
        $this->expectException(\LogicException::class);
        RequestScope::run(static fn () => RequestScope::current()->remember('x', static fn () => RequestScope::current()->remember('x', static fn () => [])));
    }
    public function testNestedScopesRestoreParentAndTasksDoNotLeak(): void
    {
        RequestScope::run(function (): void {
            $parent = RequestScope::current();
            RequestScope::run(function () use ($parent): void {
                self::assertNotSame($parent, RequestScope::current());
            });
            self::assertSame($parent, RequestScope::current());
        });
        self::assertNull(RequestScope::current());
    }
    public function testLimitBypassesWithoutGrowingUnbounded(): void
    {
        $calls = 0;
        RequestScope::run(function () use (&$calls): void {
            for ($i = 0; $i < 5; ++$i) {
                RequestScope::current()->remember('x' . $i, function () use (&$calls): array {
                    ++$calls;
                    return [];
                });
            }
        }, 1);
        self::assertSame(5, $calls);
    }
}
