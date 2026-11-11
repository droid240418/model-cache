# ModelCache with request-scoped entity snapshots

This is the `droid240418/model-cache` fork of `hyperf/model-cache`, based on upstream commit `712a83a0f23d8d06e6b8f5c3cd1b594559f90f3a` (3.2 development line). It retains the Composer package name and `Hyperf\ModelCache` namespace. Do not install another copy under a different name with the same classes. The upstream MIT license is preserved.

The optional enhanced read path is **request scope → Redis → database**. It caches complete raw attribute snapshots, not mutable Model instances, arbitrary SELECT results, relationships or DTOs. Context caching is independent of the Model Schema library, which is not a dependency.

## Requirements and installation

PHP 8.2–8.5, Hyperf 3.2, and a compatible Swoole/Engine are the supported matrix. Redis integration needs `hyperf/redis` and ext-redis in the consuming application; Context-only mode can run without them. The default metadata provider supports MySQL. Other backends require an explicit `CompleteRowMetadataProvider`; Manticore query models are not implicitly supported by the MySQL loader.

For local development place this repository beside the application and add a Composer repository:

```json
{
  "repositories": [
    {"type": "path", "url": "../model-cache", "options": {"symlink": true, "versions": {"hyperf/model-cache": "3.2.x-dev"}}}
  ],
  "require": {"hyperf/model-cache": "~3.2.0@dev"}
}
```

Run `composer update hyperf/model-cache --no-scripts --no-plugins`. The version above is a local development override, not a published stable release. For remote use, select a reviewed Fork commit/branch through a Composer VCS repository restricted to this package. Do not use the upstream Packagist release and expect Fork features to be present. Upgrade/downgrade while affected requests are drained and clear that application's affected cache keys; snapshot Hash encoding is not intended to be read simultaneously by an old upstream Hash Handler.

## Application configuration

Publish/copy `publish/model_cache.php` to `config/autoload/model_cache.php`:

```php
return [
    'context' => ['enabled' => true, 'max_entries' => 1000, 'wait_timeout' => 5.0],
    'redis' => ['enabled' => true],
    'models' => [Movie::class, Asset::class],
    'metadata_provider' => Hyperf\ModelCache\Metadata\MySqlMetadataProvider::class,
    'scope_provider' => Hyperf\ModelCache\Metadata\SafeScopeProvider::class,
];
```

Flags must be PHP booleans. Parse environment strings explicitly; `'false'` is not a boolean. Model classes opt in individually. Other models retain the upstream path. Add `Hyperf\ModelCache\Middleware\RequestCacheMiddleware::class` to HTTP middleware **after authentication/tenant context initialization**. The configured model classes use `Cacheable` and `CacheableInterface` as before.

In `databases.<connection>.cache`, use `SnapshotRedisHandler` (Hash with atomic whole-snapshot replacement) or `RedisStringHandler`. Enhanced reads reject upstream `RedisHandler`, unverified custom Handlers, and `use_default_value=true`; a partial/default-synthesized record is not a complete database snapshot. Keep your project's prefix, key template, Redis pool/database and TTL explicit. `SnapshotRedisHandler` treats old upstream hashes as misses and preserves scalar/null/binary attributes without turning NULL into empty text.

The metadata provider reads effective `information_schema.columns` once per model target per process, including INVISIBLE and retained columns. It requires metadata SELECT permission. `MySqlMetadataProvider::clear()` allows an explicit refresh after a controlled structural change; restart old workers after migrations. This metadata cache is separate from entity data and is not a Context identity map.

## Reading entities

```php
$first = Movie::findFromCache(123);
$second = Movie::findFromCache(123); // one lower-layer load within the same scope
$movies = Movie::findManyFromCache([123, 456, 404]); // only unresolved IDs load
```

Each call hydrates an independent Model. Editing `$first` without saving does not alter `$second`. Missing IDs are cached explicitly. Single/batch reads share request entries. Batch results retain first ID order, remove duplicates and omit missing records. Redis hits avoid data SELECTs across requests; Context hits avoid both Redis and DB operations. Ordinary `query()`, `select`, joins, aggregate and partial-row queries remain unchanged. `query(true)` is still the upstream batch-write invalidation Builder, **not SELECT result caching**.

Use the application's common get-by-ID entry point to call `findFromCache`; wrapping only the Manager does not intercept `query()->find()`. Netflix's `Entity::getEntityById()` is integrated for its configured MySQL models; search models retain their read-only query path.

`SafeScopeProvider` bypasses **both caches** for models with global scopes. An explicit custom provider can return a stable nonempty tenant/auth scope for request caching. Such reads deliberately bypass unscoped shared Redis because scope-changing writes cannot safely invalidate every authorization variant using upstream keys. Return `null` to bypass both caches. Never return an empty scope for tenant-filtered or authorization-filtered queries. Owner/View class identities are separate in Context; unscoped full raw Redis records can share the original table namespace. If your application needs scoped shared Redis, supply a separately reviewed complete invalidation design rather than merely adding a tenant string to the key.

## Jobs, CLI and parallel requests

```php
use Hyperf\ModelCache\Context\RequestScope;

RequestScope::run(function () {
    $a = RequestScope::spawn(fn () => Movie::findFromCache(123));
    $b = RequestScope::spawn(fn () => Movie::findFromCache(123));
    $one = $a->await();
    $two = $b->await();
});
```

For queues, wrap **each message**, not the worker loop, in `RequestScope::run()`. For CLI, wrap each task. `finally` releases the state, including non-coroutine static Context storage. There is no worker-wide singleton Model map. Without an explicit scope, Context caching is bypassed. Nesting a scope creates an independent bag and restores its parent.

`spawn()` requires a coroutine scope and shares only the request cache state, not every Context value. Propagate the required tenant/auth context explicitly in the callback. Direct `Coroutine::create()` children do not automatically share a request bag. Structured children are joined before scope completion; exceptions/timeouts release waiters, and remaining children are cancelled on scope failure. Same-key parallel loads are coalesced; different keys can load in parallel. Recursive same-key loads fail explicitly. The entry cap bounds memory and bypasses memoization on overflow. `RequestScope::current()->stats()` exposes hit/load/wait/invalidation/overflow counts without IDs or business data.

## Writes and consistency

Keep ConfigProvider's event listeners registered. Saves, deletes, batch `query(true)->update/delete`, increment/decrement, explicit `deleteCache()` and refresh/fresh invalidate request entries. A load started before invalidation cannot publish into the new request state or Redis. Raw SQL and writes from another process require explicit invalidation and are not observed automatically.

Inside a database transaction, enhanced reads bypass Context and Redis and use the current transaction connection. Begin/commit/rollback clear request entries. Writes defer Redis invalidation until the outer transaction commits; rollback does not publish uncommitted snapshots. Nested rollback conservatively retains relevant deferred invalidations until the outer outcome. Transaction listeners are essential; disabling events also disables these guarantees. Shared Redis cache-aside behavior does not provide cross-process serializable consistency.

`refresh()` and `fresh()` retain ORM database-read behavior and invalidate old snapshots; they do not silently turn `SELECT *` into an INVISIBLE-column projection. A subsequent cache-specific read obtains the complete row through its own loader.

## Table cache cleanup for migrations

```php
$manifest = ['version' => 1, 'tables' => [[
    'pool' => 'default', 'template' => '{mc:%s:m:%s}:%s:%s',
    'prefix' => 'my_project', 'table' => 'movies', 'primary_key' => 'id',
]]];
$deleted = (new Hyperf\ModelCache\Invalidation\RedisTableCacheInvalidator())
    ->invalidate($manifest, ['default' => $nativeRedisClient], quiescent: true);
```

Provide native `Redis` or `RedisCluster` clients already connected to the intended pool/database. Targets are validated before deletion. Supported templates have exactly four `%s` arguments in prefix/table/primary-key/ID order and no wildcard in their literal part. Prefix/table/key names must be nonempty safe identifiers. A template must uniquely identify that project's table; ambiguous shared namespaces require an explicit deployment mapping. SCAN visits every Cluster master and UNLINK/DEL deletes each matched key safely across slots, including negative cache entries. No KEYS or FLUSH commands are used. Network failures propagate; repeated cleanup is idempotent.

**Drain affected HTTP requests, consumers and scheduled writers first.** Otherwise old workers can repopulate keys while SCAN is running. The boolean is an explicit caller assertion, not an automatic traffic controller. Schema/deployment tooling owns the fixed manifest, persistent pending/completed publication state, DDL, retries and traffic recovery. This package only clears cache; it never executes SQL or automatically drops a table. Redis deletion cannot erase existing objects in another process's Context. No Redis generation/epoch mechanism is required.

## Tests and development

`composer install`, `composer analyse`, and `composer test -- --testsuite Unit` work without application code. Upstream integration tests were made standalone and redirected to the isolated `model_cache_test` database and dedicated Redis ports. The upstream String Handler's DefaultValueInterface test remains intentionally inapplicable.

For full tests use disposable MySQL and Redis services, **not your application's services**:

```sh
MODEL_CACHE_INTEGRATION=1 \
MC_TEST_MYSQL_PORT=13306 MC_TEST_MYSQL_PASSWORD=your_isolated_test_password \
MC_TEST_REDIS_PORT=16379 \
MC_TEST_CLUSTER_SEEDS=127.0.0.1:16380,127.0.0.1:16381,127.0.0.1:16382 \
composer test
```

Create `model_cache_test` before running; tests create and modify only its fixture tables. For local credentials in a private env file, `MODEL_CACHE_TEST_CONFIG=/absolute/private/file.env` reads only `MYSQL_ROOT_PASSWORD` without printing it. `bin/start-cluster.sh` creates a dedicated three-master Cluster container. `python3 bin/check-server.py` runs real two-worker HTTP checks in SWOOLE_BASE and SWOOLE_PROCESS; it inherits the same isolated test environment and shuts down its temporary servers. Do not run this test setup on production.

CI resolves development tools separately for PHP 8.2/8.3/8.4/8.5 × MySQL 8.4/9.7 and runs unit, upstream, real MySQL/Redis/Cluster tests, static analysis and both Swoole modes. The checked-in lock records the local PHP 8.5 development resolution; lower-PHP CI uses `composer update` to choose compatible PHPUnit rather than ignoring platform requirements. Version-matrix claims should be based on completed CI, not just this workflow definition.

No release or Packagist publication is automatic. The inherited upstream auto-close-PR workflow was removed. Fork tags only validate metadata; package distribution uses reviewed Composer VCS commits/tags. Runtime cache switches and class registration can be disabled independently of Schema integration.
