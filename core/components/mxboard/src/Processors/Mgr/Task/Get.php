<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Model\MxBoardTask;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Страница задачи: карточка + родитель + подзадачи + комментарии + журнал.
 *
 * Доступ — canView (внутри taskDetail): автор/исполнитель/соисполнитель
 * подзадачи/менеджер. Журнал показываем всегда — по нему видно, кто что реально
 * делал, в отличие от статуса, который участник выставляет сам.
 */
class Get extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $id = (int) $this->getProperty('id', 0);

        /** @var MxBoardTask|null $task */
        $task = $id > 0 ? $this->modx->getObject(MxBoardTask::class, $id) : null;
        if (!$task) {
            return $this->failure($this->modx->lexicon('mxboard_err_task_not_found'));
        }

        $query = new BoardQuery($this->modx);

        $detail = $query->taskDetail($user, $task);
        if ($detail === null) {
            return $this->failure($this->modx->lexicon('mxboard_err_view_denied'));
        }

        $detail['log'] = $query->taskLog($id);

        return $this->success('', $detail);
    }
}
