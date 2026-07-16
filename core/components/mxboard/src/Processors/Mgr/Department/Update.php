<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Department;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Правка отдела (name, active, position) — менеджер отдела.
 */
class Update extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->updateDepartment(
            $user,
            (int) $this->getProperty('id', 0),
            $this->presentProperties(['name', 'active', 'position'])
        ));
    }
}
