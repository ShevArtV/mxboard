<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Department;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Члены группы отдела — кандидаты в исполнители при создании/переназначении задачи.
 */
class Users extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $departmentId = (int) $this->getProperty('department_id', 0);
        if ($departmentId <= 0) {
            return $this->failure($this->modx->lexicon('mxboard_err_department_required'));
        }

        return $this->success('', (new BoardQuery($this->modx))->departmentUsers($departmentId));
    }
}
