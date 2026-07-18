<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Переупорядочить колонки проекта drag-n-drop: order — массив id в новом порядке.
 * position назначается по индексу; правила (полная перестановка) — в сервисе.
 */
class Reorder extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->reorderColumns(
            $user,
            (int) $this->getProperty('project_id', 0),
            $this->jsonProperty('order') ?? []
        ));
    }
}
