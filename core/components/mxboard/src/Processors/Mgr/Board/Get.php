<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Board;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;
use MxBoard\Service\TaskService;

/**
 * Доска проекта одним запросом: колонки (по position) с видимыми карточками.
 *
 * Видимость — как во всех каналах: свои карточки, менеджер — все (Visibility
 * внутри BoardQuery). Фильтры: column (ключ), mine, author_id, assignee_id.
 */
class Get extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $project = (new TaskService($this->modx))->resolveProject([
            'project_id' => (int) $this->getProperty('project_id', 0),
            'project' => (string) $this->getProperty('project', ''),
        ]);
        if (!$project) {
            return $this->failure($this->modx->lexicon('mxboard_err_project_not_found'));
        }

        $board = (new BoardQuery($this->modx))->board($user, $project, [
            'column' => (string) $this->getProperty('column', ''),
            'mine' => (bool) $this->getProperty('mine', false),
            'author_id' => (int) $this->getProperty('author_id', 0),
            'assignee_id' => (int) $this->getProperty('assignee_id', 0),
        ]);

        return $this->success('', $board);
    }
}
