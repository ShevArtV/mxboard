<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Processor;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardTask;

/**
 * Удаление карточки — только автор или обладатель права «двигать что угодно».
 * Комментарии и журнал уезжают вместе с карточкой: они объявлены composite-связями
 * в схеме, xPDO чистит их сам.
 */
class Remove extends Processor
{
    public $languageTopics = ['mxboard:default'];

    public function process()
    {
        $this->modx->lexicon->load('mxboard:default');

        $user = $this->modx->user;
        if (!$user instanceof modUser) {
            return $this->failure($this->modx->lexicon('mxboard_err_unauthenticated'));
        }

        $id = (int) $this->getProperty('id', 0);

        /** @var MxBoardTask|null $task */
        $task = $id > 0 ? $this->modx->getObject(MxBoardTask::class, $id) : null;
        if (!$task) {
            return $this->failure($this->modx->lexicon('mxboard_err_task_not_found'));
        }

        $isAuthor = (int) $user->get('id') === (int) $task->get('author_id');
        $isSuperuser = (bool) $user->get('sudo') || $this->modx->hasPermission(Transitions::PERMISSION_MOVE_ANY);
        if (!$isAuthor && !$isSuperuser) {
            return $this->failure($this->modx->lexicon('mxboard_err_remove_denied'));
        }

        $array = $task->toArray();

        if (!$task->remove()) {
            return $this->failure($this->modx->lexicon('mxboard_err_remove_failed'));
        }

        return $this->success('', $array);
    }
}
