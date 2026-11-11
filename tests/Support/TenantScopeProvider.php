<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Support;

use Hyperf\Context\Context;
use Hyperf\Database\Model\Model;
use Hyperf\ModelCache\Contract\CacheScopeProvider;

final class TenantScopeProvider implements CacheScopeProvider
{
    public function scope(Model $model): ?string
    {
        return 'tenant:' . Context::get('mc_test_tenant', 1);
    }
}
