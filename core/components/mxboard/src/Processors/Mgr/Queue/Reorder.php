<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/**
 * Переупорядочить очередь drag-n-drop: order — полный список id задач в новом порядке.
 * Правило «полная перестановка» — в сервисе.
 */
class Reorder extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new QueueService($this->modx))->reorder(
            $user,
            (int) $this->getProperty('queue_id', 0),
            $this->jsonProperty('order') ?? []
        ));
    }
}
