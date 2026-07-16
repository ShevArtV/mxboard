<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Type;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Активные типы задач отдела — для выбора типа в форме создания.
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $departmentId = (int) $this->getProperty('department_id', 0);
        if ($departmentId <= 0) {
            return $this->failure($this->modx->lexicon('mxboard_err_department_required'));
        }

        return $this->success('', (new BoardQuery($this->modx))->types($departmentId));
    }
}
