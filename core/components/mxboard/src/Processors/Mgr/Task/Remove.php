<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Удаление карточки (автор/менеджер). Подзадачи открепляются, а не удаляются —
 * это чужая работа; правило в TaskService.
 */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new TaskService($this->modx))->delete(
            $user,
            (int) $this->getProperty('id', 0),
            'mgr'
        ));
    }
}
