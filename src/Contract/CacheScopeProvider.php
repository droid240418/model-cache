<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Contract;

use Hyperf\Database\Model\Model;

interface CacheScopeProvider
{
    /** null disables BOTH cache layers; a string identifies the complete tenant/auth/read scope. */
    public function scope(Model $model): ?string;
}
