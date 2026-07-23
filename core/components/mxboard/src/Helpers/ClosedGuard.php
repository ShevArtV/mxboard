<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardTask;

/**
 * Закрытая карточка — read-only.
 *
 * Задача в стадии с `is_final` считается закрытой: её содержимое (поля, комментарии,
 * вложения, споры по дедлайну/оценке) больше не меняется, и сама карточка не удаляется.
 * Единственное разрешённое действие — смена стадии (TaskService::move), чтобы автор или
 * менеджер мог вернуть карточку из финала и снова открыть её для правок.
 *
 * Признак берём с колонки (`is_final`), а НЕ по ключу `done` и не по `closedon`:
 * ключ колонки у каждого проекта свой, а `closedon` — лишь производная отметка времени,
 * которую move() проставляет тем же флагом.
 *
 * Проверка живёт в сервисном слое (TaskService/AttachmentService), потому что manager UI,
 * REST и MCP — фасады над одними и теми же сервисами. Дублировать её в процессорах и
 * роутере нельзя: два места неизбежно разъедутся.
 */
final class ClosedGuard
{
    /** Лексиконный ключ отказа — общий для всех запрещённых операций. */
    public const ERROR = 'mxboard_err_task_closed';

    /** Задача в финальной стадии (закрыта)? Колонка не найдена — считаем открытой. */
    public static function isClosed(modX $modx, MxBoardTask $task): bool
    {
        $columnId = (int) $task->get('column_id');
        if ($columnId <= 0) {
            return false;
        }

        /** @var MxBoardColumn|null $column */
        $column = $modx->getObject(MxBoardColumn::class, $columnId);

        return $column !== null && (bool) $column->get('is_final');
    }
}
