<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Notification;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\NotificationService;

/**
 * Отметить уведомления прочитанными. ids — список id (JSON/CSV) или пусто = все свои.
 * Чужие строки недостижимы: сервис фильтрует по user_id текущего пользователя.
 */
class MarkSeen extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $ids = $this->jsonProperty('ids') ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }

        $affected = (new NotificationService($this->modx))->markSeen((int) $user->get('id'), $ids);

        return $this->success('', ['affected' => $affected]);
    }
}
