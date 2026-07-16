<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Колонки/стадии проекта (project_id = 0 — глобальный шаблон) для редактора стадий.
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->success('', (new BoardQuery($this->modx))->columns(
            (int) $this->getProperty('project_id', 0)
        ));
    }
}
