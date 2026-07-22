<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/** Вынуть задачу из очереди. */
class RemoveTask extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new QueueService($this->modx))->removeTask(
            $user,
            (int) $this->getProperty('task_id', 0)
        ));
    }
}
