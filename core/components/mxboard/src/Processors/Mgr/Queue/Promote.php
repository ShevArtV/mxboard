<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Queue;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\QueueService;

/**
 * Сделать задачу первой в её очереди: пользователь стартует очередь не с первой задачи
 * и подтвердил, что порядок изменится. Право — то же, что на перевод карточки в
 * стартовую стадию (проверяется в сервисе).
 */
class Promote extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new QueueService($this->modx))->promote(
            $user,
            (int) $this->getProperty('task_id', 0)
        ));
    }
}
