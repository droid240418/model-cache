<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Middleware;

use Hyperf\Contract\ConfigInterface;
use Hyperf\ModelCache\Context\RequestScope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RequestCacheMiddleware implements MiddlewareInterface
{
    public function __construct(private ConfigInterface $config)
    {
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (! $this->config->get('model_cache.context.enabled', false)) {
            return $handler->handle($request);
        }
        return RequestScope::run(
            static fn () => $handler->handle($request),
            $this->config->get('model_cache.context.max_entries', 1000),
            $this->config->get('model_cache.context.wait_timeout', 5.0),
        );
    }
}
