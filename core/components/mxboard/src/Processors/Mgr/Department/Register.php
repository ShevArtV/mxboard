<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Department;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Пометить группу пользователей MODX как отдел (тонкий реестр, идемпотентно).
 * Право (sudo или супер регистрируемой группы) проверяет StructureService.
 */
class Register extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->registerDepartment(
            $user,
            $this->presentProperties(['usergroup_id', 'name', 'position'])
        ));
    }
}
