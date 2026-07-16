<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Разрешение спора о дедлайне автором/менеджером: accept — дедлайн становится
 * предложенным, иначе остаётся прежний; флаг спора сбрасывается в обоих случаях.
 */
class ResolveDeadline extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new TaskService($this->modx))->resolveDeadline(
            $user,
            (int) $this->getProperty('id', 0),
            (bool) $this->getProperty('accept', false),
            'mgr'
        ));
    }
}
