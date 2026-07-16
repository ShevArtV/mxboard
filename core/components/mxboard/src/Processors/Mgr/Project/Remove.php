<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Project;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Удаление пустого проекта (без задач) — менеджер отдела. Правило в сервисе.
 */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->removeProject(
            $user,
            (int) $this->getProperty('id', 0)
        ));
    }
}
