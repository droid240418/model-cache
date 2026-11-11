<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Contract;

use Hyperf\Database\Model\Model;

interface CompleteRowMetadataProvider
{
    public function columns(Model $model): array;
}
