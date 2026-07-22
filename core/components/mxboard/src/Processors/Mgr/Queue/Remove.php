<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/** Удаление очереди. Задачи остаются, у них лишь обнуляется членство. */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new QueueService($this->modx))->remove(
            $user,
            (int) $this->getProperty('id', 0)
        ));
    }
}
