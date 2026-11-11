<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Context;

use Hyperf\Context\Context;
use Hyperf\Engine\Coroutine;

final class RequestScope
{
    public static function current(): ?RequestCacheState
    {
        return Context::get(self::class);
    }
    /** Internal attachment; callers should use run()/spawn(). */
    public static function attach(?RequestCacheState $state): void
    {
        if ($state === null) {
            Context::destroy(self::class);
        } else {
            Context::set(self::class, $state);
        }
    }
    public static function run(callable $callback, int $limit = 1000, float $timeout = 5.0): mixed
    {
        $previous = self::current();
        $state = new RequestCacheState($limit, $timeout);
        self::attach($state);
        try {
            $result = $callback();
            foreach ($state->children as $child) {
                $child->await();
            }
            return $result;
        } finally {
            $state->close();
            foreach ($state->children as $child) {
                $child->cancel();
            }
            self::attach($previous);
        }
    }
    public static function spawn(callable $callback, float $timeout = 5.0): RequestTask
    {
        $state = self::current();
        if (! $state || Coroutine::id() <= 0) {
            throw new \LogicException('spawn requires a coroutine request scope.');
        }
        return $state->children[] = new RequestTask($state, $callback, $timeout);
    }
    public static function invalidate(): void
    {
        self::current()?->invalidate();
    }
}
