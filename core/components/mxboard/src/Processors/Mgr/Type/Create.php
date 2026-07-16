<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Type;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Создание типа задачи с полями (инвариант ≥1 поле — в сервисе). Менеджер отдела.
 */
class Create extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $data = $this->presentProperties(['department_id', 'key', 'name', 'description', 'position']);
        $data['fields'] = $this->jsonProperty('fields') ?? [];

        return $this->fromResult((new StructureService($this->modx))->createType($user, $data));
    }
}
