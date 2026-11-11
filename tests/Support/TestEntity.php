<?php

declare(strict_types=1);

namespace HyperfTest\ModelCache\Support;

use Hyperf\DbConnection\Model\Model;
use Hyperf\ModelCache\Cacheable;
use Hyperf\ModelCache\CacheableInterface;

/** @phpstan-consistent-constructor */
class TestEntity extends Model implements CacheableInterface
{
    use Cacheable;
    protected ?string $table = 'mc_entities';
    protected array $guarded = [];
    public bool $timestamps = false;
}
