<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

/**
 * Создание карточки из менеджера (v2: тип, дедлайн, исполнитель обязательны;
 * parent_id — подзадача). Вся валидация и журнал — в TaskService: правила не
 * должны зависеть от канала запроса.
 */
class Create extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $data = $this->presentProperties([
            'project', 'project_id', 'parent_id',
            'type', 'type_id', 'title', 'tor', 'priority',
            'deadline', 'assignee', 'assignee_id',
        ]);

        // JSON-поля: объект из JSON-тела или строка из form-data — сервис примет обе,
        // но нормализуем здесь, чтобы не слать заведомо битую строку.
        foreach (['fields', 'meta'] as $key) {
            $value = $this->jsonProperty($key);
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $this->fromResult((new TaskService($this->modx))->create($user, $data, 'mgr'));
    }
}
