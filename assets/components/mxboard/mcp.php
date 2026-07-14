<?php

declare(strict_types=1);

/**
 * MCP-эндпоинт mxBoard — remote MCP-сервер по HTTP (Streamable HTTP transport).
 *
 * Агенту (Claude Code, Codex, opencode) не нужно ничего ставить локально: только
 * URL этого файла и Bearer-токен. Тело — JSON-RPC 2.0, ответ — application/json.
 *
 * Здесь только транспорт: бутстрап MODX, аутентификация по токену и HTTP-коды.
 * Протокол и инструменты — в MxBoard\Mcp\Server.
 *
 * @package mxboard
 */

// MODX 3 эту константу сам не читает, но её проверяют плагины и extra, чтобы не
// плеваться HTML в API-ответ. Ставим до бутстрапа.
if (!defined('MODX_API_MODE')) {
    define('MODX_API_MODE', true);
}

// Ошибки — в лог, не в тело ответа: HTML-варнинг посреди JSON ломает клиента.
@ini_set('display_errors', '0');

// Клиент MCP — не браузер: Set-Cookie ему не нужен. Но сессия нужна НАМ, см. ниже.
@ini_set('session.use_cookies', '0');

/* ---------- Бутстрап MODX ---------- */

// Корень MODX — подъёмом по дереву от assets/components/mxboard/: раскладка assets
// на shared-хостинге бывает нестандартной, поэтому ищем config.core.php, а не считаем уровни.
$configCore = '';
$dir = __DIR__;
for ($i = 0; $i < 8; $i++) {
    if (is_file($dir . '/config.core.php')) {
        $configCore = $dir . '/config.core.php';
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if ($configCore === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    exit('{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"MODX config.core.php not found"}}');
}

require_once $configCore;
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$mxboardCore = MODX_CORE_PATH . 'components/mxboard/';

// Компонент ставится без composer-зависимостей, поэтому своего vendor/autoload у него
// обычно нет — регистрируем PSR-4 (MxBoard\ → src/) сами. Если vendor всё же собран, он
// подхватится первым, и этот загрузчик просто не сработает.
if (is_file($mxboardCore . 'vendor/autoload.php')) {
    require_once $mxboardCore . 'vendor/autoload.php';
}
spl_autoload_register(static function (string $class) use ($mxboardCore): void {
    if (!str_starts_with($class, 'MxBoard\\')) {
        return;
    }
    $file = $mxboardCore . 'src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$modx = new \MODX\Revolution\modX();

// Контекст mgr и ЖИВАЯ сессия — не для входа по кукам, а ради прав.
//
// Ловушка MODX: modAccessibleObject::checkPolicy() проверяет политики, только если
// getSessionState() === SESSION_STATE_INITIALIZED; без сессии он возвращает true на
// любой вопрос. То есть в «чистом» API-режиме (session_enabled => false) вызов
// $modx->hasPermission('mxboard_move_any') был бы true для КОГО УГОДНО — а Transitions
// считает обладателя этого права суперпользователем, обходящим правила колонок.
// Итог: любой агент закрывал бы чужие задачи. Поэтому сессию поднимаем (куки при этом
// не отдаём, см. session.use_cookies выше) и гасим её в конце запроса.
$modx->initialize('mgr');
$modx->lexicon->load('mxboard:default');

// Сессия жила только ради вычисления политик — не оставляем мусорных строк в modx_session.
register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }
});

/* ---------- Ответы ---------- */

/**
 * @param array<string, mixed>|null $payload
 */
function mxb_send(int $status, ?array $payload = null): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    if ($payload !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function mxb_rpc_error(int $status, int $code, string $message, mixed $id = null): never
{
    mxb_send($status, [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => $code, 'message' => $message],
    ]);
}

/* ---------- HTTP-метод ---------- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    header('Allow: POST, OPTIONS');
    mxb_send(204);
}

// GET в Streamable HTTP — это подписка на SSE-поток. Мы stateless и сервер→клиент
// сообщений не шлём; спека разрешает ответить 405, клиенты это переживают.
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    mxb_rpc_error(405, -32600, 'Only POST is supported: this endpoint has no SSE stream');
}

/* ---------- Тело ---------- */

$raw = (string) file_get_contents('php://input');
$request = json_decode($raw, true);

if (!is_array($request) || $request === []) {
    mxb_rpc_error(400, -32700, 'Parse error: expected a JSON-RPC 2.0 object');
}

// Батчи (массив запросов) убраны из спеки MCP начиная с ревизии 2025-03-26.
if (array_is_list($request)) {
    mxb_rpc_error(400, -32600, 'Invalid Request: JSON-RPC batching is not supported');
}

$id = array_key_exists('id', $request) ? $request['id'] : null;

/* ---------- Доступ ---------- */

if (!(bool) $modx->getOption('mxboard.mcp_enabled', null, true)) {
    mxb_rpc_error(403, -32001, $modx->lexicon('mxboard_err_mcp_disabled') ?: 'MCP endpoint is disabled', $id);
}

$bearer = \MxBoard\Helpers\TokenAuth::bearerFromRequest();
$user = $bearer !== '' ? \MxBoard\Helpers\TokenAuth::authenticate($modx, $bearer) : null;
unset($bearer, $raw); // секрет дальше не живёт и никуда не пишется

if (!$user) {
    header('WWW-Authenticate: Bearer realm="mxboard"');
    mxb_rpc_error(401, -32001, $modx->lexicon('mxboard_err_token_invalid') ?: 'Invalid token', $id);
}

// Всё, что дальше, происходит от имени владельца токена: и правила переходов,
// и политики MODX считаются для него, а журнал доски пишет его user_id.
$modx->user = $user;

/* ---------- Обработка ---------- */

$server = new \MxBoard\Mcp\Server($modx, $user);
$response = $server->handle($request);

// Нотификация (запрос без id): по JSON-RPC ответа быть не должно — только 202.
if ($response === null) {
    mxb_send(202);
}

// Ошибки JSON-RPC (-32601 и прочие) едут с HTTP 200: транспорт отработал, ошибка — в теле.
mxb_send(200, $response);
