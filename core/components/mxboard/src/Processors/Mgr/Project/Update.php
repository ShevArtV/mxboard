<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Project;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Правка проекта (name, description, active, position) — менеджер отдела.
 */
class Update extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->updateProject(
            $user,
            (int) $this->getProperty('id', 0),
            $this->presentProperties(['name', 'description', 'active', 'position'])
        ));
    }
}
