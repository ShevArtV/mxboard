<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Type;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;
use MxBoard\Service\TaskService;

/**
 * Схема типа (встроенные поля + поля типа) — по ней UI строит динамическую форму задачи.
 */
class Schema extends ServiceProcessor
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

        $schema = (new BoardQuery($this->modx))->typeSchema($project, (string) $this->getProperty('type', ''));
        if ($schema === null) {
            return $this->failure($this->modx->lexicon('mxboard_err_type_not_found'));
        }

        return $this->success('', $schema);
    }
}
