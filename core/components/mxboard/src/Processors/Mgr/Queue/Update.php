<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/** Правка очереди. Проект не меняется — это был бы перенос очереди с чужими задачами. */
class Update extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new QueueService($this->modx))->update(
            $user,
            (int) $this->getProperty('id', 0),
            $this->presentProperties(['key', 'name', 'description', 'active', 'position'])
        ));
    }
}
