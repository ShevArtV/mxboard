<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/** Создание очереди в проекте. Право (менеджер отдела проекта) — в сервисе. */
class Create extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new QueueService($this->modx))->create(
            $user,
            $this->presentProperties(['project_id', 'key', 'name', 'description', 'active', 'position'])
        ));
    }
}
