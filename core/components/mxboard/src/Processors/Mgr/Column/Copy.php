<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Скопировать колонки в проект из источника (source_id: id проекта или 0 — глобальный
 * шаблон). Доступно только пока в проекте нет задач — правило в сервисе.
 */
class Copy extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->copyColumns(
            $user,
            (int) $this->getProperty('project_id', 0),
            (int) $this->getProperty('source_id', 0)
        ));
    }
}
