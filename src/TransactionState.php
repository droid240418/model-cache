<?php

declare(strict_types=1);

namespace Hyperf\ModelCache;

use Hyperf\Context\Context;

/** Per-transaction coroutine state; never shared with request child coroutines. */
final class TransactionState
{
    public static function defer(string $connection, string $class, array $ids): void
    {
        $items = Context::get(self::class, []);
        $items[$connection][$class] = array_values(array_unique(array_merge($items[$connection][$class] ?? [], $ids)));
        Context::set(self::class, $items);
    }
    public static function take(string $connection): array
    {
        $items = Context::get(self::class, []);
        $result = $items[$connection] ?? [];
        unset($items[$connection]);
        if ($items) {
            Context::set(self::class, $items);
        } else {
            Context::destroy(self::class);
        }
        return $result;
    }
}
