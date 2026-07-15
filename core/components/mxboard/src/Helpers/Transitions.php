<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;

/**
 * Кто и куда вправе двигать карточку.
 *
 * Права здесь — НЕ групповой ACL MODX: он оперирует группами, а нам нужна роль
 * относительно конкретной карточки («автор вот этой задачи»). Поэтому правило
 * перехода лежит на колонке (`move_roles`), а проверка — здесь, и вызывается
 * из процессоров, каким бы каналом ни пришёл запрос (менеджер, REST, MCP).
 *
 * «Менеджер» карточки = глобальный sudo ИЛИ супер-пользователь группы отдела,
 * которому принадлежит проект карточки (см. isManager). Именно менеджер обходит
 * правила колонок и закрывает чужие задачи.
 */
class Transitions
{
    /** Роль: тот, кто поставил задачу. */
    public const ROLE_AUTHOR = 'author';

    /** Роль: тот, кто взял задачу в работу. */
    public const ROLE_ASSIGNEE = 'assignee';

    /** Роль: любой пользователь, имеющий доступ к доске. */
    public const ROLE_ANY = 'any';

    /** Право «двигать что угодно куда угодно» — обходит правила колонки. */
    public const PERMISSION_MOVE_ANY = 'mxboard_move_any';

    /**
     * Может ли пользователь перевести карточку в колонку.
     *
     * Блокировку финальной колонки открытыми подзадачами тут НЕ проверяем — это делает
     * TaskService::move (нужен запрос к потомкам); здесь только ролевая авторизация.
     *
     * @return array{allowed: bool, reason: string} reason — лексиконный ключ, пустой при allowed.
     */
    public static function can(modX $modx, modUser $user, MxBoardTask $task, MxBoardColumn $target): array
    {
        $userId = (int) $user->get('id');
        $authorId = (int) $task->get('author_id');
        $assigneeId = (int) $task->get('assignee_id');

        $isAuthor = $userId > 0 && $userId === $authorId;
        $isAssignee = $userId > 0 && $userId === $assigneeId;

        // Менеджер карточки — вне правил колонки (разбор заторов, откаты, закрытие).
        $isManager = self::isManager($modx, $user, $task);

        // Закрытие — отдельный, более строгий случай.
        if ((bool) $target->get('is_final')) {
            if (!$isAuthor && !$isManager) {
                return ['allowed' => false, 'reason' => 'mxboard_err_close_author_only'];
            }

            // Автор, который сам же и исполнитель, закрывает сам себя — это самоаттестация.
            // По умолчанию запрещено; включается настройкой mxboard.allow_self_close.
            $allowSelfClose = (bool) $modx->getOption('mxboard.allow_self_close', null, false);
            if ($isAuthor && $isAssignee && !$allowSelfClose && !$isManager) {
                return ['allowed' => false, 'reason' => 'mxboard_err_self_close'];
            }

            return ['allowed' => true, 'reason' => ''];
        }

        if ($isManager) {
            return ['allowed' => true, 'reason' => ''];
        }

        foreach (self::roles($target) as $role) {
            $ok = match ($role) {
                self::ROLE_ANY => true,
                self::ROLE_AUTHOR => $isAuthor,
                self::ROLE_ASSIGNEE => $isAssignee,
                default => self::inGroup($modx, $user, $role),
            };
            if ($ok) {
                return ['allowed' => true, 'reason' => ''];
            }
        }

        return ['allowed' => false, 'reason' => 'mxboard_err_move_denied'];
    }

    /**
     * Менеджер карточки: глобальный sudo или супер-пользователь группы её отдела.
     *
     * Это тот, кто в правах и видимости обходит ограничения исполнителя.
     */
    public static function isManager(modX $modx, modUser $user, MxBoardTask $task): bool
    {
        if (self::isSuperuser($modx, $user)) {
            return true;
        }

        $usergroupId = self::departmentGroupId($modx, $task);

        return $usergroupId > 0 && self::isGroupSuper($modx, $user, $usergroupId);
    }

    /**
     * Глобальный супер-пользователь (обходит правила во всех отделах).
     *
     * ВНИМАНИЕ, ловушка MODX: `modAccessibleObject::checkPolicy()` возвращает **true на любой
     * вопрос**, если сессия не инициализирована (ранний выход при
     * `getSessionState() !== SESSION_STATE_INITIALIZED`). То есть в API-режиме без сессии
     * `hasPermission()` объявил бы суперпользователем кого угодно — и любой агент закрывал
     * бы чужие задачи, ради запрета которых всё и затевалось.
     *
     * Поэтому право спрашиваем только там, где ответу можно верить. Нет живой сессии —
     * остаётся единственный надёжный признак: флаг `sudo` в самой записи пользователя.
     */
    public static function isSuperuser(modX $modx, modUser $user): bool
    {
        if ((bool) $user->get('sudo')) {
            return true;
        }

        if ($modx->getSessionState() !== modX::SESSION_STATE_INITIALIZED) {
            return false;
        }

        return (bool) $modx->hasPermission(self::PERMISSION_MOVE_ANY);
    }

    /**
     * Супер-пользователь конкретной группы отдела.
     *
     * В MODX 3 «сила» роли — это `modUserGroupRole.authority` (int, default 9999),
     * где МЕНЬШЕ значение = больше прав (Administrator authority=1 наследует права
     * Member authority=9999). Членство с `role = 0` = роли нет. Значит участник —
     * супер своей группы, если его роль назначена (`role != 0`) и её authority не
     * превышает порог `mxboard.group_admin_authority`.
     *
     * Порог `<= 0` = фича выключена (штатных ролей с authority 0 нет, минимум 1).
     * Проверка — обычный JOIN, а не hasPermission: сессии в API-режиме нет.
     */
    public static function isGroupSuper(modX $modx, modUser $user, int $usergroupId): bool
    {
        $userId = (int) $user->get('id');
        if ($userId <= 0 || $usergroupId <= 0) {
            return false;
        }

        $threshold = (int) $modx->getOption('mxboard.group_admin_authority', null, 0);
        if ($threshold <= 0) {
            return false;
        }

        $c = $modx->newQuery(modUserGroupMember::class);
        $c->innerJoin(modUserGroupRole::class, 'Role');
        $c->where([
            'modUserGroupMember.member' => $userId,
            'modUserGroupMember.user_group' => $usergroupId,
            'modUserGroupMember.role:!=' => 0,
            'Role.authority:<=' => $threshold,
        ]);

        return (int) $modx->getCount(modUserGroupMember::class, $c) > 0;
    }

    /** ID группы MODX отдела, которому принадлежит проект карточки. 0, если не разрешить. */
    public static function departmentGroupId(modX $modx, MxBoardTask $task): int
    {
        /** @var MxBoardProject|null $project */
        $project = $modx->getObject(MxBoardProject::class, (int) $task->get('project_id'));
        if (!$project) {
            return 0;
        }

        /** @var MxBoardDepartment|null $department */
        $department = $modx->getObject(MxBoardDepartment::class, (int) $project->get('department_id'));

        return $department ? (int) $department->get('usergroup_id') : 0;
    }

    /**
     * Роли колонки: CSV вида "author,assignee" или "group:Managers".
     *
     * @return list<string>
     */
    public static function roles(MxBoardColumn $column): array
    {
        $raw = trim((string) $column->get('move_roles'));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /** Роль вида "group:Name" — членство в группе пользователей MODX. */
    private static function inGroup(modX $modx, modUser $user, string $role): bool
    {
        if (!str_starts_with($role, 'group:')) {
            return false;
        }

        $group = trim(substr($role, 6));

        return $group !== '' && $user->isMember($group);
    }
}
