<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Комментарий к карточке. Право (canView) и журнал — в TaskService.
 */
class Comment extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new TaskService($this->modx))->comment(
            $user,
            (int) $this->getProperty('id', 0),
            (string) $this->getProperty('content', ''),
            'mgr',
            (bool) $this->getProperty('allow_empty', false)
        ));
    }
}
