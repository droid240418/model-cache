<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Context;

use Hyperf\Engine\Coroutine;

/** Request-owned snapshots, never model instances or a worker-wide identity map. */
final class RequestCacheState
{
    private array $entries = [];
    private array $flights = [];
    private int $revision = 0;
    private bool $closed = false;
    private array $stats = ['hits' => 0, 'loads' => 0, 'waits' => 0, 'invalidations' => 0, 'overflow_bypasses' => 0];
    public array $children = [];

    public function __construct(private readonly int $limit = 1000, private readonly float $timeout = 5.0)
    {
        if ($limit < 1 || $timeout <= 0) {
            throw new \InvalidArgumentException('Cache limit and timeout must be positive.');
        }
    }
    public function revision(): int
    {
        return $this->revision;
    }
    private function isClosed(): bool
    {
        return $this->closed;
    }
    public function invalidate(): void
    {
        ++$this->revision;
        ++$this->stats['invalidations'];
        $this->entries = [];
    }
    public function stats(): array
    {
        return $this->stats + ['entries' => count($this->entries), 'inflight' => count($this->flights)];
    }
    public function close(): void
    {
        $this->closed = true;
        $this->invalidate();
        foreach ($this->flights as $flight) {
            $flight->finish();
        }
        $this->flights = [];
    }
    public function remember(string $key, callable $loader): ?array
    {
        return $this->rememberMany([$key], static fn (array $keys): array => [$key => $loader()])[$key];
    }
    /** Loader returns a snapshot or null for EVERY requested key. */
    public function rememberMany(array $keys, callable $loader): array
    {
        $keys = array_values(array_unique($keys));
        while (true) {
            if ($this->isClosed()) {
                throw new \LogicException('Request cache scope is closed.');
            }
            $new = count(array_filter($keys, fn ($key) => ! array_key_exists($key, $this->entries) && ! isset($this->flights[$key])));
            if (count($this->entries) + count($this->flights) + $new > $this->limit) {
                ++$this->stats['overflow_bypasses'];
                return $loader($keys); // Bounded memory; documented overflow bypass.
            }
            $revision = $this->revision;
            $owned = [];
            foreach ($keys as $key) {
                if (array_key_exists($key, $this->entries)) {
                    ++$this->stats['hits'];
                    continue;
                }
                if (isset($this->flights[$key])) {
                    if ($this->flights[$key]->owner === Coroutine::id()) {
                        throw new \LogicException('Recursive entity load for the same key.');
                    }
                    continue;
                }
                $owned[$key] = $this->flights[$key] = new Flight(Coroutine::id(), $revision);
            }
            // Load owned keys before waiting for other batches, preventing overlapping-batch deadlocks.
            try {
                if ($owned) {
                    ++$this->stats['loads'];
                    $loaded = $loader(array_keys($owned));
                    foreach ($owned as $key => $flight) {
                        if (! array_key_exists($key, $loaded)) {
                            throw new \LogicException('Incomplete entity loader result.');
                        }
                    }
                    foreach ($owned as $key => $flight) {
                        if (! $this->isClosed() && $revision === $this->revision()) {
                            $this->entries[$key] = $loaded[$key] ?? Missing::Record;
                        }
                    }
                }
            } finally {
                foreach ($owned as $key => $flight) {
                    unset($this->flights[$key]);
                    $flight->finish();
                }
            }
            foreach ($keys as $key) {
                if (isset($this->flights[$key])) {
                    ++$this->stats['waits'];
                    $this->flights[$key]->wait($this->timeout);
                }
            }
            if ($this->isClosed()) {
                throw new \LogicException('Request cache scope ended during load.');
            }
            if ($revision !== $this->revision()) {
                continue;
            }
            $result = [];
            foreach ($keys as $key) {
                if (! array_key_exists($key, $this->entries)) {
                    continue 2;
                }
                $result[$key] = $this->entries[$key] === Missing::Record ? null : $this->entries[$key];
            }
            return $result;
        }
    }
}
