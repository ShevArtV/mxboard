<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Оспаривание дедлайна исполнителем: предлагает новую дату с причиной.
 * Сам дедлайн меняет только автор — через ResolveDeadline.
 */
class DisputeDeadline extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $proposed = $this->getProperty('proposed_date');
        $timestamp = is_numeric($proposed)
            ? (int) $proposed
            : (int) (strtotime(trim((string) $proposed)) ?: 0);

        return $this->fromResult((new TaskService($this->modx))->disputeDeadline(
            $user,
            (int) $this->getProperty('id', 0),
            $timestamp,
            (string) $this->getProperty('reason', ''),
            'mgr'
        ));
    }
}
