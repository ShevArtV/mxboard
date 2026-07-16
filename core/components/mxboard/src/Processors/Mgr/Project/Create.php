<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Project;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Создание проекта с колонками (или из глобального шаблона). Инвариант
 * «ровно одна initial/final» — в сервисе.
 */
class Create extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $data = $this->presentProperties(['department_id', 'key', 'name', 'description', 'position']);

        $columns = $this->jsonProperty('columns');
        if ($columns !== null) {
            $data['columns'] = $columns;
        }

        return $this->fromResult((new StructureService($this->modx))->createProject($user, $data));
    }
}
