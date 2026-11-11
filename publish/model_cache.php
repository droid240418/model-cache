<?php

declare(strict_types=1);

return [
    'context' => ['enabled' => false, 'max_entries' => 1000, 'wait_timeout' => 5.0],
    'redis' => ['enabled' => true],
    // Opt-in class list; unknown models retain upstream behavior.
    'models' => [],
    'metadata_provider' => \Hyperf\ModelCache\Metadata\MySqlMetadataProvider::class,
    'scope_provider' => \Hyperf\ModelCache\Metadata\SafeScopeProvider::class,
];
