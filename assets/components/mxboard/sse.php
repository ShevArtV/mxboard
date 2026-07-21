<?php

declare(strict_types=1);

/**
 * SSE-стрим уведомлений mxBoard для админки.
 *
 * Транспорт: Server-Sent Events (EventSource). Авторизация — по КУКЕ mgr-сессии
 * (EventSource шлёт куки same-origin), а не по токену в URL: эндпоинт лежит под тем же
 * доменом, что и менеджер, и логиниться отдельно не нужно.
 *
 * Ловушка session-lock: живая сессия держит блокировку файла сессии на всё время запроса,
 * а стрим живёт десятки секунд — без освобождения все параллельные mgr-запросы этого
 * пользователя висли бы. Поэтому сразу после получения юзера делаем session_write_close().
 *
 * Соединение короткоживущее (mxboard.sse_lifetime, дефолт 25с): на shared-хостинге долгие
 * запросы рвут прокси/FPM. Клиент переподключается сам, передавая Last-Event-ID —
 * докачиваем пропущенное по курсору id.
 *
 * @package mxboard
 */

@ini_set('display_errors', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

/* ---------- Бутстрап MODX (как в rest.php) ---------- */

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
    header('HTTP/1.1 500 Internal Server Error');
    exit;
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
$modx->initialize('mgr');

$user = $modx->user;
$userId = $user ? (int) $user->get('id') : 0;

// Отпускаем блокировку сессии как можно раньше: дальше работаем без неё.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($userId <= 0) {
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

if (!(bool) $modx->getOption('mxboard.sse_enabled', null, true)) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

/* ---------- SSE-заголовки ---------- */

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // nginx: не буферизировать стрим

@set_time_limit(0);
ignore_user_abort(false);
while (ob_get_level() > 0) {
    @ob_end_flush();
}

/* ---------- Курсор ---------- */

// Last-Event-ID (после реконнекта) приоритетнее query-параметра ?lastId.
function mxb_sse_cursor(int $notificationId, int $logId): string
{
    return 'n' . max(0, $notificationId) . ':l' . max(0, $logId);
}

function mxb_sse_parse_cursor(string $raw): array
{
    if (preg_match('/^n(\d+):l(\d+)$/', $raw, $m)) {
        return [(int) $m[1], (int) $m[2]];
    }

    // Backward compatibility: older clients sent only notification id.
    if (ctype_digit($raw)) {
        return [(int) $raw, 0];
    }

    return [0, 0];
}

[$lastId, $lastLogId] = mxb_sse_parse_cursor((string) ($_SERVER['HTTP_LAST_EVENT_ID'] ?? ''));
if ($lastId <= 0) {
    $lastId = (int) ($_GET['lastId'] ?? 0);
}
if ($lastLogId <= 0) {
    $lastLogId = (int) ($_GET['lastLogId'] ?? 0);
}

$lifetime = max(5, min(120, (int) $modx->getOption('mxboard.sse_lifetime', null, 25)));
$poll = max(1, min(10, (int) $modx->getOption('mxboard.sse_poll_interval', null, 3)));
$retryMs = $poll * 1000;

$service = new \MxBoard\Service\NotificationService($modx);
$query = new \MxBoard\Service\BoardQuery($modx);
if ($lastLogId <= 0) {
    $lastLogId = $query->latestLogId();
}

echo 'retry: ' . $retryMs . "\n\n";
@flush();

$deadline = time() + $lifetime;

while (time() < $deadline) {
    if (connection_aborted()) {
        break;
    }

    $items = $service->sinceId($userId, $lastId, 50);
    foreach ($items as $item) {
        $lastId = max($lastId, (int) $item['id']);
        echo 'id: ' . mxb_sse_cursor($lastId, $lastLogId) . "\n";
        echo 'event: notification' . "\n";
        echo 'data: ' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    $events = $query->liveEvents($user, $lastLogId, 100);
    foreach ($events as $event) {
        $lastLogId = max($lastLogId, (int) $event['id']);
        echo 'id: ' . mxb_sse_cursor($lastId, $lastLogId) . "\n";
        echo 'event: board-event' . "\n";
        echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }

    // Комментарий-пинг держит соединение живым сквозь прокси в тихие периоды.
    if (!$items && !$events) {
        echo ': ping ' . time() . "\n\n";
    }
    @flush();

    sleep($poll);
}

// Явное завершение: клиент переподключится с Last-Event-ID = $lastId.
echo 'event: reconnect' . "\n";
echo 'data: {}' . "\n\n";
@flush();
