<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Priority;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\PriorityService;

/**
 * Правка приоритета (name, color, value). Уникальность value/name — в сервисе.
 */
class Update extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new PriorityService($this->modx))->update(
            $user,
            (int) $this->getProperty('id', 0),
            $this->presentProperties(['name', 'color', 'value'])
        ));
    }
}
