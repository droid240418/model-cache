<?php

declare(strict_types=1);

namespace Hyperf\ModelCache\Listener;

use Hyperf\Database\Events\TransactionBeginning;
use Hyperf\Database\Events\TransactionCommitted;
use Hyperf\Database\Events\TransactionRolledBack;
use Hyperf\Database\Model\Events\Saving;
use Hyperf\Database\Model\Events\Saved;
use Hyperf\Database\Model\Events\Deleting;
use Hyperf\Database\Model\Events\Deleted;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\ModelCache\Context\RequestScope;
use Hyperf\ModelCache\Manager;
use Hyperf\ModelCache\TransactionState;
use Psr\Container\ContainerInterface;

final class RequestCacheListener implements ListenerInterface
{
    public function __construct(private ContainerInterface $container)
    {
    }
    public function listen(): array
    {
        return [Saving::class, Saved::class, Deleting::class, Deleted::class,
            TransactionBeginning::class, TransactionCommitted::class, TransactionRolledBack::class];
    }
    public function process(object $event): void
    {
        RequestScope::invalidate();
        if ($event instanceof Saved || $event instanceof Deleted) {
            $model = $event->getModel();
            if ($model instanceof \Hyperf\ModelCache\CacheableInterface && $model->getConnection()->transactionLevel() > 0) {
                TransactionState::defer(\Hyperf\ModelCache\Metadata\ModelConnection::name($model), $model::class, [$model->getKey()]);
            }
        }
        if ($event instanceof TransactionCommitted && $event->connection->transactionLevel() === 0) {
            $pending = TransactionState::take((string) $event->connectionName);
            $manager = $this->container->get(Manager::class);
            foreach ($pending as $class => $ids) {
                $manager->destroy($ids, $class);
            }
        }
        if ($event instanceof TransactionBeginning && $event->connection->transactionLevel() === 1) {
            TransactionState::take((string) $event->connectionName);
        }
        if ($event instanceof TransactionRolledBack && $event->connection->transactionLevel() === 0) {
            TransactionState::take((string) $event->connectionName);
        }
    }
}
