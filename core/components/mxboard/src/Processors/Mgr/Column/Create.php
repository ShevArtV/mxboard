<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Добавление колонки в проект или шаблон (project_id = 0). Флаги initial/final
 * при создании не назначаются — перенос через Update; правила в сервисе.
 */
class Create extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->createColumn(
            $user,
            $this->presentProperties(['project_id', 'key', 'name', 'move_roles', 'color', 'position'])
        ));
    }
}
