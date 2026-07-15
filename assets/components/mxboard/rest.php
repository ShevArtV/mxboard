<?php

declare(strict_types=1);

/**
 * REST-эндпоинт mxBoard — HTTP API поверх того же сервисного слоя, что и MCP.
 *
 * Здесь только транспорт: бутстрап MODX, авторизация (Bearer-токен ИЛИ Basic логин+пароль
 * MODX — оба через ApiAuth), разбор маршрута и HTTP-коды. Маршруты и вызовы сервисов — в
 * MxBoard\Rest\Router. Ответ — JSON {success, message, data}.
 *
 * Маршрут берётся из PATH_INFO (`rest.php/tasks/5/move`); если хостинг его не отдаёт —
 * фолбэк на `?path=tasks/5/move`.
 *
 * @package mxboard
 */

if (!defined('MODX_API_MODE')) {
    define('MODX_API_MODE', true);
}

@ini_set('display_errors', '0');
@ini_set('session.use_cookies', '0');

/* ---------- Бутстрап MODX (как в mcp.php) ---------- */

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

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function mxb_rest_send(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($configCore === '') {
    mxb_rest_send(500, ['success' => false, 'message' => 'MODX config.core.php not found', 'data' => null]);
}

require_once $configCore;
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$mxboardCore = MODX_CORE_PATH . 'components/mxboard/';
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

// Живая сессия mgr ради КОРРЕКТНЫХ политик (та же ловушка checkPolicy, что в mcp.php:
// без сессии hasPermission == true для кого угодно). Куки не отдаём (session.use_cookies=0).
$modx->initialize('mgr');
$modx->lexicon->load('mxboard:default');

register_shutdown_function(static function (): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }
});

/* ---------- Метод и preflight ---------- */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    header('Allow: GET, POST, PATCH, DELETE, OPTIONS');
    mxb_rest_send(204, ['success' => true, 'message' => '', 'data' => null]);
}

/* ---------- Гейт ---------- */

if (!(bool) $modx->getOption('mxboard.api_enabled', null, true)) {
    mxb_rest_send(403, ['success' => false, 'message' => $modx->lexicon('mxboard_err_api_disabled') ?: 'REST API is disabled', 'data' => null]);
}

/* ---------- Авторизация: Bearer или Basic ---------- */

$user = \MxBoard\Helpers\ApiAuth::authenticate($modx);
if (!$user) {
    header('WWW-Authenticate: Bearer realm="mxboard", Basic realm="mxboard"');
    mxb_rest_send(401, ['success' => false, 'message' => $modx->lexicon('mxboard_err_token_invalid') ?: 'Invalid credentials', 'data' => null]);
}
$modx->user = $user;

/* ---------- Маршрут ---------- */

$rawPath = (string) ($_SERVER['PATH_INFO'] ?? '');
if ($rawPath === '' && isset($_GET['path'])) {
    $rawPath = (string) $_GET['path'];
}
$segments = array_values(array_filter(explode('/', trim($rawPath, '/')), static fn ($s): bool => $s !== ''));

$query = $_GET;
unset($query['path']);

$body = [];
if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
    $raw = (string) file_get_contents('php://input');
    if (trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            mxb_rest_send(400, ['success' => false, 'message' => 'Malformed JSON body', 'data' => null]);
        }
        $body = $decoded;
    }
}

try {
    $router = new \MxBoard\Rest\Router($modx, $user);
    $response = $router->dispatch($method, $segments, $query, $body);
} catch (\Throwable $e) {
    $modx->log(\MODX\Revolution\modX::LOG_LEVEL_ERROR, '[mxBoard][rest] ' . $e->getMessage());
    mxb_rest_send(500, ['success' => false, 'message' => 'Internal error', 'data' => null]);
}

mxb_rest_send($response['status'], $response['body']);
