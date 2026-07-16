<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Правка колонки (name, move_roles, stage_key, position; key неизменен).
 * is_initial/is_final = 1 переносит флаг с прежнего носителя — инвариант цел.
 */
class Update extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->updateColumn(
            $user,
            (int) $this->getProperty('id', 0),
            $this->presentProperties(['name', 'move_roles', 'stage_key', 'position', 'is_initial', 'is_final'])
        ));
    }
}
