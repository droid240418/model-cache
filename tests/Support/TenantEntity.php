<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Support;

use Hyperf\Context\Context;

class TenantEntity extends TestEntity
{
    protected function boot(): void
    {
        parent::boot();
        static::addGlobalScope('tenant', static fn ($query) => $query->where('amount', Context::get('mc_test_tenant', 1)));
    }
}
