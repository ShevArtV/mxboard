<?php

declare(strict_types=1);

namespace MxBoard\Helpers;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardToken;

/**
 * Аутентификация агента по Bearer-токену (MCP и REST).
 *
 * В БД лежит только sha256-хэш токена: утечка таблицы не даёт рабочих ключей.
 * Сам токен не попадает ни в один лог — ни в modx-лог, ни в mxboard_log:
 * журнал доски пишет user_id и канал, но не секрет.
 *
 * Токен привязан к пользователю MODX, поэтому отдельной модели прав нет:
 * права агента — это права его пользователя.
 */
final class TokenAuth
{
    /**
     * Пользователь, которому принадлежит токен, либо null.
     *
     * Возвращаем null на любой отказ (нет токена, отозван, пользователь заблокирован)
     * и без подробностей: клиенту незачем знать, какая именно проверка не прошла.
     */
    public static function authenticate(modX $modx, string $bearer): ?modUser
    {
        $bearer = trim($bearer);
        if ($bearer === '') {
            return null;
        }

        $hash = hash('sha256', $bearer);

        /** @var MxBoardToken|null $token */
        $token = $modx->getObject(MxBoardToken::class, ['token_hash' => $hash, 'active' => true]);
        if (!$token) {
            return null;
        }

        // Выборка и так шла по точному хэшу, но сверяем ещё раз и только hash_equals:
        // сравнение секретов через == оставляет тайминговый канал.
        if (!hash_equals((string) $token->get('token_hash'), $hash)) {
            return null;
        }

        /** @var modUser|null $user */
        $user = $modx->getObject(modUser::class, (int) $token->get('user_id'));
        if (!$user || !(bool) $user->get('active')) {
            return null;
        }

        $token->set('lastusedon', time());
        $token->save();

        return $user;
    }

    /**
     * Токен из заголовка `Authorization: Bearer <token>`.
     *
     * Заголовок доезжает по-разному: nginx + PHP-FPM кладёт его в HTTP_AUTHORIZATION
     * только если пробросили fastcgi_param; при внутреннем редиректе он превращается
     * в REDIRECT_HTTP_AUTHORIZATION; часть SAPI отдаёт его вообще только через
     * *_request_headers(). Поэтому проверяем все источники, а не один.
     */
    public static function bearerFromRequest(): string
    {
        $header = '';

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $header = $_SERVER[$key];
                break;
            }
        }

        if ($header === '') {
            $header = self::fromRequestHeaders();
        }

        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $m)) {
            return '';
        }

        return $m[1];
    }

    /** Authorization через apache_request_headers()/getallheaders() — там, где они есть. */
    private static function fromRequestHeaders(): string
    {
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
