<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/**
 * Очереди проекта. С with_tasks=1 — вместе с задачами очереди (для аккордеона на доске).
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->success('', (new QueueService($this->modx))->queues(
            (int) $this->getProperty('project_id', 0),
            (bool) $this->getProperty('with_tasks', false)
        ));
    }
}
