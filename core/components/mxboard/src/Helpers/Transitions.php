<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardTask;

/**
 * Кто и куда вправе двигать карточку.
 *
 * Права здесь — НЕ групповой ACL MODX: он оперирует группами, а нам нужна роль
 * относительно конкретной карточки («автор вот этой задачи»). Поэтому правило
 * перехода лежит на колонке (`move_roles`), а проверка — здесь, и вызывается
 * из процессоров, каким бы каналом ни пришёл запрос (менеджер, REST, MCP).
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
     * @return array{allowed: bool, reason: string} reason — лексиконный ключ, пустой при allowed.
     */
    public static function can(modX $modx, modUser $user, MxBoardTask $task, MxBoardColumn $target): array
    {
        $userId = (int) $user->get('id');
        $authorId = (int) $task->get('author_id');
        $assigneeId = (int) $task->get('assignee_id');

        $isAuthor = $userId > 0 && $userId === $authorId;
        $isAssignee = $userId > 0 && $userId === $assigneeId;

        // Оператор с глобальным правом — вне правил колонки (разбор заторов, откаты).
        $isSuperuser = self::isSuperuser($modx, $user);

        // Закрытие — отдельный, более строгий случай.
        if ((bool) $target->get('is_final')) {
            if (!$isAuthor && !$isSuperuser) {
                return ['allowed' => false, 'reason' => 'mxboard_err_close_author_only'];
            }

            // Автор, который сам же и исполнитель, закрывает сам себя — это самоаттестация.
            // По умолчанию запрещено; включается настройкой mxboard.allow_self_close.
            $allowSelfClose = (bool) $modx->getOption('mxboard.allow_self_close', null, false);
            if ($isAuthor && $isAssignee && !$allowSelfClose && !$isSuperuser) {
                return ['allowed' => false, 'reason' => 'mxboard_err_self_close'];
            }

            return ['allowed' => true, 'reason' => ''];
        }

        if ($isSuperuser) {
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
     * Обходит ли пользователь правила колонок.
     *
     * ВНИМАНИЕ, ловушка MODX: `modAccessibleObject::checkPolicy()` возвращает **true на любой
     * вопрос**, если сессия не инициализирована (см. `modAccessibleObject::checkPolicy()` —
     * ранний выход при `getSessionState() !== SESSION_STATE_INITIALIZED`). То есть в API-режиме
     * без сессии `hasPermission()` объявил бы суперпользователем кого угодно — и любой агент
     * закрывал бы чужие задачи, ради запрета которых всё и затевалось.
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
