<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;

/**
 * Кто какие задачи видит.
 *
 * ВАЖНО: детальный доступ и канбан — РАЗНЫЕ вещи.
 *
 *  - canView (детальный просмотр + комментирование) шире: сюда попадает и соисполнитель
 *    подзадачи — он вправе открыть и прокомментировать родителя.
 *  - boardCondition (карточки на доске) уже: у соисполнителя подзадачи родитель НЕ
 *    появляется карточкой на его канбане — там только его собственные задачи. Поэтому
 *    фильтр доски — «автор/исполнитель», а связь через подзадачу его не расширяет.
 *
 * Менеджер (супер группы отдела / глобальный sudo) видит на доске всё в проекте.
 */
class Visibility
{
    /**
     * Может ли пользователь открыть карточку: детальный просмотр и комментирование.
     *
     * Автор / исполнитель самой задачи, ИЛИ автор / исполнитель любой её подзадачи,
     * ИЛИ менеджер (супер группы отдела / глобальный sudo).
     */
    public static function canView(modX $modx, modUser $user, MxBoardTask $task): bool
    {
        $userId = (int) $user->get('id');
        if ($userId <= 0) {
            return false;
        }

        if ($userId === (int) $task->get('author_id') || $userId === (int) $task->get('assignee_id')) {
            return true;
        }

        if (self::isSubtaskParty($modx, $userId, (int) $task->get('id'))) {
            return true;
        }

        return Transitions::isManager($modx, $user, $task);
    }

    /**
     * Видит ли пользователь ВСЕ карточки проекта (менеджерский охват).
     *
     * Глобальный sudo или супер-пользователь группы отдела, которому принадлежит проект.
     */
    public static function seesAllInProject(modX $modx, modUser $user, MxBoardProject $project): bool
    {
        if (Transitions::isSuperuser($modx, $user)) {
            return true;
        }

        /** @var MxBoardDepartment|null $department */
        $department = $modx->getObject(MxBoardDepartment::class, (int) $project->get('department_id'));
        $usergroupId = $department ? (int) $department->get('usergroup_id') : 0;

        return $usergroupId > 0 && Transitions::isGroupSuper($modx, $user, $usergroupId);
    }

    /**
     * Условие видимости для выборки карточек доски, добавляемое К УЖЕ scoped-по-проекту запросу.
     *
     * Пустой массив = ограничения нет (менеджер видит все карточки проекта). Иначе —
     * «мои» карточки: автор ИЛИ исполнитель. Подзадачи сюда НЕ подмешиваются намеренно.
     *
     * @return array<mixed> фрагмент условия для xPDOQuery::where (AND к project-scope)
     */
    public static function boardCondition(modX $modx, modUser $user, MxBoardProject $project): array
    {
        if (self::seesAllInProject($modx, $user, $project)) {
            return [];
        }

        $userId = (int) $user->get('id');

        // (author_id = uid OR assignee_id = uid)
        return [
            [
                'author_id' => $userId,
                'OR:assignee_id:=' => $userId,
            ],
        ];
    }

    /**
     * Условие видимости для КРОСС-ПРОЕКТНОЙ выборки задач отдела (обзор руководителя),
     * добавляемое к уже scoped-по-отделу запросу.
     *
     * То же правило, что и на доске, но точка отсчёта — отдел, а не проект: у обзора
     * проекта на входе нет вовсе. Пустой массив = ограничения нет (менеджер отдела
     * видит все карточки его проектов). Иначе — «мои»: автор ИЛИ исполнитель.
     *
     * @return array<mixed> фрагмент условия для xPDOQuery::where (AND к department-scope)
     */
    public static function departmentCondition(modX $modx, modUser $user, int $departmentId): array
    {
        if (Transitions::isDepartmentManager($modx, $user, $departmentId)) {
            return [];
        }

        $userId = (int) $user->get('id');

        // (author_id = uid OR assignee_id = uid)
        return [
            [
                'MxBoardTask.author_id' => $userId,
                'OR:MxBoardTask.assignee_id:=' => $userId,
            ],
        ];
    }

    /** Является ли пользователь автором или исполнителем хотя бы одной подзадачи данной задачи. */
    private static function isSubtaskParty(modX $modx, int $userId, int $taskId): bool
    {
        if ($taskId <= 0) {
            return false;
        }

        $c = $modx->newQuery(MxBoardTask::class);
        $c->where([
            'parent_id' => $taskId,
            [
                'author_id' => $userId,
                'OR:assignee_id:=' => $userId,
            ],
        ]);

        return (int) $modx->getCount(MxBoardTask::class, $c) > 0;
    }
}
