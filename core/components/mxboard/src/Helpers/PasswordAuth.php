<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;

/**
 * Аутентификация по логину+паролю пользователя MODX (HTTP Basic) — для REST и MCP.
 *
 * Это «как в админке»: те же учётные данные менеджера. Пароль проверяется штатным
 * `modUser::passwordMatches()` (хеш и соль — забота ядра); сам пароль нигде не хранится
 * и не логируется. Права дальше — права этого пользователя.
 *
 * Размен против Bearer-токена: пароль от аккаунта в конфиге клиента опаснее отзываемого
 * токена (см. TokenAuth). Оба метода поддержаны, выбор — за пользователем.
 */
final class PasswordAuth
{
    /**
     * Пользователь по логину+паролю, либо null на любой отказ (нет пользователя,
     * заблокирован, пароль не совпал) — без подробностей клиенту.
     */
    public static function authenticate(modX $modx, string $username, string $password): ?modUser
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return null;
        }

        /** @var modUser|null $user */
        $user = $modx->getObject(modUser::class, ['username' => $username]);
        if (!$user || !(bool) $user->get('active')) {
            return null;
        }

        if (!$user->passwordMatches($password)) {
            return null;
        }

        return $user;
    }
}
