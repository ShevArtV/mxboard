<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;

/**
 * Единая точка аутентификации агентских каналов (MCP и REST).
 *
 * Принимает ОБА метода — клиент выбирает сам:
 *   - `Authorization: Bearer <token>`  → отзываемый токен (TokenAuth), удобно агентам;
 *   - `Authorization: Basic <base64>`  → логин+пароль MODX (PasswordAuth), «как в админке».
 *
 * Оба фасада (mcp.php, rest.php) зовут только этот резолвер: расхождению в способах
 * входа между каналами взяться неоткуда.
 */
final class ApiAuth
{
    /**
     * Пользователь по заголовку Authorization, либо null. Сам секрет наружу не отдаём
     * и не логируем — вернувшийся объект уже «пользователь», а не токен/пароль.
     */
    public static function authenticate(modX $modx): ?modUser
    {
        $header = self::authorizationHeader();
        if ($header === '') {
            return null;
        }

        if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return TokenAuth::authenticate($modx, $m[1]);
        }

        if (preg_match('/^\s*Basic\s+(\S+)\s*$/i', $header, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded === false || !str_contains($decoded, ':')) {
                return null;
            }
            [$username, $password] = explode(':', $decoded, 2);

            return PasswordAuth::authenticate($modx, $username, $password);
        }

        return null;
    }

    /**
     * Сырой заголовок Authorization из всех источников, где он может оказаться.
     *
     * Доезжает по-разному: nginx + PHP-FPM кладёт в HTTP_AUTHORIZATION только если
     * пробросили fastcgi_param; при внутреннем редиректе он становится
     * REDIRECT_HTTP_AUTHORIZATION; часть SAPI отдаёт его лишь через *_request_headers().
     */
    private static function authorizationHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }

        $headers = [];
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();
        }

        foreach ((array) $headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                return $value;
            }
        }

        return '';
    }
}
