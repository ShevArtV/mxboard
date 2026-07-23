<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Overview;

use MODX\Revolution\modUser;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Справочники обзора одним вызовом: подведомственные отделы, а для выбранного отдела —
 * его проекты, участники и стадии.
 *
 * Один запрос вместо N: список стадий приходится собирать по колонкам ВСЕХ проектов
 * отдела (наборы у проектов могут различаться), и делать это с фронта — значит дёргать
 * ColumnApi по каждому проекту. Приоритеты фронт берёт из window.MxBoardConfig.
 *
 * Доступ к содержимому отдела — его менеджеру, как и у GetList: справочник участников и
 * проектов отдаётся в объёме «весь отдел», и открывать его рядовому участнику незачем.
 * Без department_id вернётся только список отделов, которыми руководит сам вызывающий, —
 * рядовому участнику он придёт пустым.
 */
class Meta extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $query = new BoardQuery($this->modx);

        // Список подведомственных отделов отдаём всегда: фронту он нужен ДО выбора
        // отдела, чтобы селектор не предлагал то, на что процессор ответит отказом.
        $out = ['departments' => $query->managedDepartments($user)];

        $departmentId = (int) $this->getProperty('department_id', 0);
        if ($departmentId <= 0) {
            return $this->success('', $out);
        }

        if (!$this->modx->getObject(MxBoardDepartment::class, $departmentId)) {
            return $this->failure($this->modx->lexicon('mxboard_err_department_not_found'));
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, $departmentId)) {
            return $this->failure($this->modx->lexicon('mxboard_err_overview_denied'));
        }

        $out['projects'] = $query->departmentProjects($departmentId);
        $out['users'] = $query->departmentUsers($departmentId);
        $out['stages'] = $query->departmentStages($departmentId);

        return $this->success('', $out);
    }
}
