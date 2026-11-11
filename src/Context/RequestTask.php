<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Context;

use Hyperf\Engine\Channel;
use Hyperf\Engine\Coroutine;

final class RequestTask
{
    private Channel $completion;
    private bool $done = false;
    private bool $observed = false;
    private mixed $result = null;
    private ?\Throwable $error = null;
    private int $id;

    public function __construct(private readonly RequestCacheState $state, callable $callback, private readonly float $timeout)
    {
        $this->completion = new Channel(1);
        $this->id = Coroutine::create(function () use ($state, $callback): void {
            $previous = RequestScope::current();
            RequestScope::attach($state);
            try {
                $this->result = $callback();
            } catch (\Throwable $error) {
                $this->error = $error;
            } finally {
                RequestScope::attach($previous);
                $this->done = true;
                $this->completion->push(true, 0.01);
            }
        })->getId();
    }
    public function await(): mixed
    {
        if (! $this->done && $this->completion->pop($this->timeout) === false) {
            $this->observed = true;
            $this->cancel();
            throw new \RuntimeException('Request child coroutine timed out.');
        }
        $this->observed = true;
        if ($this->error) {
            throw $this->error;
        }
        return $this->result;
    }
    public function cancel(): void
    {
        if (! $this->done && \Swoole\Coroutine::exists($this->id)) {
            $this->state->cancelOwner($this->id);
            \Swoole\Coroutine::cancel($this->id);
        }
    }
    /** Scope teardown propagates unobserved failures, not failures already handled by the caller. */
    public function join(): void
    {
        if (! $this->observed) {
            $this->await();
        }
    }
}
