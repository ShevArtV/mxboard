<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Overview;

use MODX\Revolution\modUser;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Табличный обзор отдела: задачи всех его проектов плоским списком с множественными
 * фильтрами (приоритет, проект, автор, исполнитель, стадия).
 *
 * Экран руководителя, поэтому доступ — менеджеру отдела (супер группы отдела или sudo).
 * Проверка здесь, а не только гейтом вкладки во фронте: `cfg.is_manager` лишь прячет
 * вкладку, а процессор доступен любому аутентифицированному пользователю менеджера —
 * без этой проверки прямой вызов раскрыл бы весь отдел. Второй рубеж — скоуп
 * Visibility::departmentCondition внутри BoardQuery.
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $departmentId = (int) $this->getProperty('department_id', 0);
        if ($departmentId <= 0) {
            return $this->failure($this->modx->lexicon('mxboard_err_department_required'));
        }
        if (!$this->modx->getObject(MxBoardDepartment::class, $departmentId)) {
            return $this->failure($this->modx->lexicon('mxboard_err_department_not_found'));
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, $departmentId)) {
            return $this->failure($this->modx->lexicon('mxboard_err_overview_denied'));
        }

        // Множественные фильтры приходят JSON-строкой: useApi кладёт массив в FormData
        // поэлементно и ломает его, поэтому фронт сериализует их (withJson), как
        // columns/order в других вызовах.
        $filters = [
            'priority' => $this->jsonProperty('priority') ?? [],
            'project_id' => $this->jsonProperty('project_id') ?? [],
            'author_id' => $this->jsonProperty('author_id') ?? [],
            'assignee_id' => $this->jsonProperty('assignee_id') ?? [],
            'stage' => $this->jsonProperty('stage') ?? [],
        ];

        return $this->success('', (new BoardQuery($this->modx))->departmentTasks($user, $departmentId, $filters));
    }
}
