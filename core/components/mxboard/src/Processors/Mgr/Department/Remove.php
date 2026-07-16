<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Department;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Снять пометку «отдел» с группы. Только с пустого отдела — правило в сервисе.
 */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->removeDepartment(
            $user,
            (int) $this->getProperty('id', 0)
        ));
    }
}
