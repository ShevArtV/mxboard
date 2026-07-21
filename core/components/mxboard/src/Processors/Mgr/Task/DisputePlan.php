<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Оспаривание планового времени исполнителем: предлагает свою оценку в часах с причиной.
 * Сам план меняет только автор — через ResolvePlan.
 */
class DisputePlan extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $proposed = $this->getProperty('proposed_hours');

        return $this->fromResult((new TaskService($this->modx))->disputePlan(
            $user,
            (int) $this->getProperty('id', 0),
            is_numeric($proposed) ? (int) round((float) $proposed) : 0,
            (string) $this->getProperty('reason', ''),
            'mgr'
        ));
    }
}
