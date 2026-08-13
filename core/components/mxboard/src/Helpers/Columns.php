<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardTask;

/**
 * Fallback колонок: у проекта без собственных колонок доска берёт глобальный
 * шаблон (project_id = 0) — задачи такого проекта ссылаются column_id прямо на
 * строки шаблона.
 *
 * Отсюда класс ошибки #2607-217: как только у проекта появляются СВОИ колонки,
 * scope переключается с 0 на project_id, а старые задачи остаются на шаблоне.
 * Видимую стадию это не меняет (доска группирует по `key`), но всё, что сравнивает
 * raw column_id — в первую очередь автозапуск очереди, — молча промахивается.
 * Поэтому материализация шаблона обязана переносить задачи проекта по ключу
 * (remapTasksToScope), а код, которому важна стадия, спрашивать effectiveFor().
 */
final class Columns
{
    /**
     * Эффективный project_id для ЧТЕНИЯ/поиска колонок: сам проект, если у него есть
     * собственные колонки; иначе 0 (глобальный шаблон).
     */
    public static function scope(modX $modx, int $projectId): int
    {
        if ($projectId === 0) {
            return 0;
        }
        $own = (int) $modx->getCount(MxBoardColumn::class, ['project_id' => $projectId]);

        return $own > 0 ? $projectId : 0;
    }

    /** Есть ли у проекта собственные (материализованные) колонки. */
    public static function hasOwn(modX $modx, int $projectId): bool
    {
        return $projectId > 0
            && (int) $modx->getCount(MxBoardColumn::class, ['project_id' => $projectId]) > 0;
    }

    /**
     * Колонка задачи, приведённая к scope её проекта: если raw column_id ссылается на
     * чужой scope (шаблон при своих колонках или наоборот), возвращается одноимённая
     * колонка scope'а. Нет такой по ключу — возвращается фактическая, как есть: это
     * данные, требующие решения человека, а не повод молча переставить карточку.
     */
    public static function effectiveFor(modX $modx, MxBoardTask $task): ?MxBoardColumn
    {
        $columnId = (int) $task->get('column_id');
        /** @var MxBoardColumn|null $column */
        $column = $columnId > 0 ? $modx->getObject(MxBoardColumn::class, $columnId) : null;
        if (!$column) {
            return null;
        }

        $scope = self::scope($modx, (int) $task->get('project_id'));
        if ((int) $column->get('project_id') === $scope) {
            return $column;
        }

        /** @var MxBoardColumn|null $sameKey */
        $sameKey = $modx->getObject(
            MxBoardColumn::class,
            ['project_id' => $scope, 'key' => (string) $column->get('key')]
        );

        return $sameKey ?: $column;
    }

    /**
     * Привести column_id задачи к scope её проекта. Возвращает true, если карточка
     * действительно переставлена (и сохранена) — вызывающий пишет это в лог.
     */
    public static function normalize(modX $modx, MxBoardTask $task): bool
    {
        $effective = self::effectiveFor($modx, $task);
        if (!$effective || (int) $effective->get('id') === (int) $task->get('column_id')) {
            return false;
        }

        $task->set('column_id', (int) $effective->get('id'));

        return (bool) $task->save();
    }

    /**
     * Перевести задачи проекта на набор колонок $byKey (key => id) по ключу текущей
     * колонки; ключа в наборе нет — карточка уходит на $fallbackId (начальную стадию),
     * как это уже делает StructureService::resetColumns.
     *
     * Задачи, уже стоящие в колонках набора, не трогаются. Сохранение построчное, БЕЗ
     * транзакции: вызывающий держит свою и откатывает при null (ошибка записи).
     *
     * @param array<string, int> $byKey
     *
     * @return int|null число переставленных карточек или null при ошибке сохранения
     */
    public static function remapTasksToScope(modX $modx, int $projectId, array $byKey, int $fallbackId): ?int
    {
        if ($projectId <= 0 || $byKey === [] || $fallbackId <= 0) {
            return null;
        }

        $targetIds = array_flip(array_map('intval', array_values($byKey)));
        /** @var array<int, string> $keyById колонка id => key (кэш на выборку) */
        $keyById = [];
        $moved = 0;

        /** @var MxBoardTask $task */
        foreach ($modx->getCollection(MxBoardTask::class, ['project_id' => $projectId]) as $task) {
            $cid = (int) $task->get('column_id');
            if ($cid <= 0 || isset($targetIds[$cid])) {
                continue;
            }
            if (!isset($keyById[$cid])) {
                /** @var MxBoardColumn|null $column */
                $column = $modx->getObject(MxBoardColumn::class, $cid);
                if (!$column) {
                    continue;
                }
                $keyById[$cid] = (string) $column->get('key');
            }

            $task->set('column_id', $byKey[$keyById[$cid]] ?? $fallbackId);
            if (!$task->save()) {
                return null;
            }
            $moved++;
        }

        return $moved;
    }
}
