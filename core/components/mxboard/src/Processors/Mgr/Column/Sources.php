<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Список источников для копирования колонок в проект (шаблон + проекты отдела со
 * своими колонками). Если источник один — фронт копирует без диалога.
 */
class Sources extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->columnSources(
            $user,
            (int) $this->getProperty('project_id', 0)
        ));
    }
}
