<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Priority;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\PriorityService;

/**
 * Удалить приоритет. Последний оставшийся не удаляется — правило в сервисе.
 */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new PriorityService($this->modx))->remove(
            $user,
            (int) $this->getProperty('id', 0)
        ));
    }
}
