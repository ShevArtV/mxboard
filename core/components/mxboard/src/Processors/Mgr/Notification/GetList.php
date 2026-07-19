<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Notification;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\NotificationService;

/**
 * Лента уведомлений текущего пользователя + счётчик непрочитанных.
 *
 * Это первичная загрузка при открытии доски; дальше поток идёт по SSE (sse.php).
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $limit = (int) $this->getProperty('limit', 50);
        $data = (new NotificationService($this->modx))->listFor((int) $user->get('id'), $limit);

        return $this->success('', $data);
    }
}
