<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Metadata;

use Hyperf\Database\Model\Model;
use Hyperf\ModelCache\Contract\CacheScopeProvider;

final class SafeScopeProvider implements CacheScopeProvider
{
    public function scope(Model $model): ?string
    {
        return $model->getGlobalScopes() ? null : '';
    }
}
