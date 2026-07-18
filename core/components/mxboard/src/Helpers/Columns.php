<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;

/**
 * Fallback колонок: у проекта без собственных колонок доска берёт глобальный
 * шаблон (project_id = 0). Материализовать шаблон в проект нельзя, пока в проекте
 * есть задачи (см. StructureService::copyColumns) — поэтому задачи проекта на
 * fallback ссылаются column_id прямо на строки шаблона, и коллизий не возникает.
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
}
