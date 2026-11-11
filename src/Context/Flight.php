<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Context;

use Hyperf\Engine\Channel;

final class Flight
{
    public array $waiters = [];
    public function __construct(public readonly int $owner, public readonly int $revision)
    {
    }
    public function finish(): void
    {
        foreach ($this->waiters as $waiter) {
            $waiter->push(true, 0.01);
        }
        $this->waiters = [];
    }
    public function wait(float $timeout): void
    {
        $channel = new Channel(1);
        $this->waiters[] = $channel;
        if ($channel->pop($timeout) === false) {
            throw new \RuntimeException('Timed out waiting for an entity cache load.');
        }
    }
}
