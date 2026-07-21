<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Разрешение спора о плановом времени автором/менеджером: accept — планом становится
 * предложенная оценка, иначе остаётся прежняя; флаг спора сбрасывается в обоих случаях.
 */
class ResolvePlan extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new TaskService($this->modx))->resolvePlan(
            $user,
            (int) $this->getProperty('id', 0),
            (bool) $this->getProperty('accept', false),
            'mgr'
        ));
    }
}
