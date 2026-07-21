<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Правка карточки (автор/менеджер): title, tor, priority, deadline, план в часах, тип, поля,
 * переназначение исполнителя. column_id здесь НЕ меняется сознательно: движение —
 * только через Move, где работают правила переходов и пишется журнал.
 */
class Update extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $data = $this->presentProperties([
            'title', 'tor', 'priority',
            'deadline', 'plan_hours', 'assignee', 'assignee_id',
            'type', 'type_id',
        ]);

        if ($this->getProperty('fields') !== null) {
            $data['fields'] = $this->jsonProperty('fields');
        }

        return $this->fromResult((new TaskService($this->modx))->update(
            $user,
            (int) $this->getProperty('id', 0),
            $data,
            'mgr'
        ));
    }
}
