<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Priority;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\PriorityService;

/**
 * Добавить приоритет в глобальный справочник. Инварианты (уникальность value/name) —
 * в сервисе.
 */
class Create extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new PriorityService($this->modx))->create(
            $user,
            $this->presentProperties(['name', 'color', 'value'])
        ));
    }
}
