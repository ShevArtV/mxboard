<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Сброс колонок проекта к дефолтным: удаляет собственные колонки проекта (задачи
 * переносятся на шаблон по ключу), проект возвращается на глобальный шаблон.
 * Шаблон (project_id = 0) сбросить нельзя — правила в сервисе.
 */
class Reset extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->resetColumns(
            $user,
            (int) $this->getProperty('project_id', 0)
        ));
    }
}
