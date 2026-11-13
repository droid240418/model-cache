<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Unit;

use Hyperf\ModelCache\Invalidation\RedisTableCacheInvalidator;
use PHPUnit\Framework\TestCase;

final class RedisCleanupFailureTest extends TestCase
{
    private function manifest(): array
    {
        return ['version' => 1, 'tables' => [['pool' => 'test', 'template' => '%s:%s:%s:%s', 'prefix' => 'project', 'table' => 'entities', 'primary_key' => 'id']]];
    }

    public function testDisconnectedNativeClientCannotConfirmCleanup(): void
    {
        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('not connected');
        (new RedisTableCacheInvalidator())->invalidate($this->manifest(), ['test' => new \Redis()], true);
    }

    public function testFalseScanWithErrorAndZeroCursorIsNotAnEmptySuccess(): void
    {
        $client = new class extends \Redis {
            public function isConnected(): bool { return true; }
            public function clearLastError(): bool { return true; }
            public function scan(string|int|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false { $iterator = 0; return false; }
            public function getLastError(): ?string { return 'NOPERM SCAN denied'; }
        };
        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('SCAN failed');
        (new RedisTableCacheInvalidator())->invalidate($this->manifest(), ['test' => $client], true);
    }

    public function testEmptyScanWithoutErrorRemainsSuccessful(): void
    {
        $client = new class extends \Redis {
            public function isConnected(): bool { return true; }
            public function clearLastError(): bool { return true; }
            public function scan(string|int|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false { $iterator = 0; return false; }
            public function getLastError(): ?string { return null; }
        };
        self::assertSame(0, (new RedisTableCacheInvalidator())->invalidate($this->manifest(), ['test' => $client], true));
    }

    public function testImplicitClientPrefixIsRejectedBeforeScanning(): void
    {
        $client = new class extends \Redis {
            public function getOption($option): mixed { return 'application:'; }
        };
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without OPT_PREFIX');
        (new RedisTableCacheInvalidator())->invalidate($this->manifest(), ['test' => $client], true);
    }
}
