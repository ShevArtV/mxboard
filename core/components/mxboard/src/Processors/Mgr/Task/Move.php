<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Перевод карточки в колонку. Проверку прав перехода делает TaskService/Transitions —
 * здесь её дублировать нельзя, иначе два места разъедутся.
 */
class Move extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new TaskService($this->modx))->move(
            $user,
            (int) $this->getProperty('id', 0),
            (string) $this->getProperty('column', ''),
            (string) $this->getProperty('note', ''),
            'mgr'
        ));
    }
}
