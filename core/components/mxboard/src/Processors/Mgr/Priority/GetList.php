<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Priority;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\PriorityService;

/**
 * Глобальный справочник приоритетов (проектно независимый) для вкладки «Приоритеты».
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->success('', (new PriorityService($this->modx))->all());
    }
}
