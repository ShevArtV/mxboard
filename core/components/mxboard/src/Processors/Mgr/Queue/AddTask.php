<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/**
 * Поставить задачу в очередь. queue_id = 0 — «в единственную очередь проекта»
 * (UI в этом случае не показывает диалог выбора).
 */
class AddTask extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new QueueService($this->modx))->addTask(
            $user,
            (int) $this->getProperty('task_id', 0),
            (int) $this->getProperty('queue_id', 0)
        ));
    }
}
