<?php

/**
 * Smoke-тест mxBoard на стенде: проверяет ровно те обещания, которые легко нарушить.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _smoke_remote.php
 *
 * Что проверяем:
 *   1. Доска default с пятью колонками создана резолвером.
 *   2. Захват карточки атомарен: из двух одновременных попыток выигрывает ровно одна.
 *   3. Исполнитель НЕ может закрыть задачу (в done переводит только автор).
 *   4. Автор — может.
 *   5. Автор, который сам же исполнитель, закрыть не может (запрет самоаттестации).
 *   6. Журнал переходов пишется.
 *
 * Тестовые данные за собой убирает.
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardBoard;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\TaskService;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbsmoke');
$modx->initialize('mgr');
$modx->getService('lexicon', 'modLexicon');
$modx->lexicon->load('mxboard:default');

$corePath = MODX_CORE_PATH . 'components/mxboard/';
if (is_file($corePath . 'vendor/autoload.php')) {
    require_once $corePath . 'vendor/autoload.php';
}
if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Тестовый пользователь-агент. */
function ensureUser(modX $modx, string $username): modUser
{
    $user = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$user) {
        $user = $modx->newObject(modUser::class);
        $user->set('username', $username);
        $profile = $modx->newObject(modUserProfile::class);
        $profile->set('email', $username . '@mxboard.test');
        $user->addOne($profile);
        $user->set('active', 1);
        $user->save();
    }

    return $user;
}

echo "== mxBoard smoke ==\n";

// 1. Доска и колонки.
$board = $modx->getObject(MxBoardBoard::class, ['key' => 'default']);
check('доска default существует', (bool) $board);
if (!$board) {
    exit(1);
}

$columns = $modx->getCollection(MxBoardColumn::class, ['board_id' => $board->get('id')]);
check('у доски 5 колонок', count($columns) === 5, 'найдено: ' . count($columns));

$done = $modx->getObject(MxBoardColumn::class, ['board_id' => $board->get('id'), 'key' => 'done']);
check('колонка done — финальная', $done && (bool) $done->get('is_final'));

$service = new TaskService($modx);

$author = ensureUser($modx, 'mxb_test_author');
$worker = ensureUser($modx, 'mxb_test_worker');
$worker2 = ensureUser($modx, 'mxb_test_worker2');

// 2. Создание карточки автором.
$res = $service->create($author, ['title' => 'SMOKE: тестовая задача', 'tor' => '# Проверка'], 'api');
check('карточка создана', $res['success'], (string) $res['message']);
$taskId = (int) ($res['object']['id'] ?? 0);
if ($taskId === 0) {
    exit(1);
}

// Переводим в ready — оттуда её можно взять.
$res = $service->move($author, $taskId, 'ready', '', 'api');
check('автор перевёл задачу в ready', $res['success'], (string) $res['message']);

// 3. Гонка: два исполнителя одновременно пытаются взять одну карточку.
$r1 = $service->take($worker, $taskId, 'mcp');
$r2 = $service->take($worker2, $taskId, 'mcp');
$winners = (int) $r1['success'] + (int) $r2['success'];
check('захват атомарен: выиграл ровно один', $winners === 1, "успехов: {$winners}");

$task = $modx->getObject(MxBoardTask::class, $taskId);
$assignee = (int) $task->get('assignee_id');
check('исполнитель проставлен', $assignee > 0);

$holder = $assignee === (int) $worker->get('id') ? $worker : $worker2;
$loser = $assignee === (int) $worker->get('id') ? $worker2 : $worker;

// Проигравший получил внятную причину, а не «успех».
$loserRes = $assignee === (int) $worker->get('id') ? $r2 : $r1;
check(
    'проигравший получил отказ «уже взяли»',
    !$loserRes['success'] && str_contains(mb_strtolower((string) $loserRes['message']), 'взял'),
    (string) $loserRes['message']
);

// 4. Исполнитель доводит до review — можно.
$res = $service->move($holder, $taskId, 'review', 'готово к проверке', 'mcp');
check('исполнитель перевёл в review', $res['success'], (string) $res['message']);

// 5. Исполнитель пытается закрыть — НЕЛЬЗЯ.
$res = $service->move($holder, $taskId, 'done', 'я всё сделал', 'mcp');
check('исполнитель НЕ может закрыть задачу', !$res['success'], 'ответ: ' . json_encode($res['success']));

// 6. Посторонний тоже не может.
$res = $service->move($loser, $taskId, 'done', '', 'mcp');
check('посторонний НЕ может закрыть задачу', !$res['success']);

// 7. Автор — может.
$res = $service->move($author, $taskId, 'done', 'принято', 'mgr');
check('автор закрыл задачу', $res['success'], (string) $res['message']);

$task = $modx->getObject(MxBoardTask::class, $taskId);
check('closedon проставлен', (int) $task->get('closedon') > 0);

// 8. Самоаттестация: автор == исполнитель → закрыть нельзя.
$res = $service->create($author, ['title' => 'SMOKE: сам себе задача'], 'api');
$selfId = (int) ($res['object']['id'] ?? 0);
$service->move($author, $selfId, 'ready', '', 'api');
$service->take($author, $selfId, 'mcp'); // автор сам берёт свою задачу
$service->move($author, $selfId, 'review', '', 'mcp');
$res = $service->move($author, $selfId, 'done', '', 'mcp');
check(
    'автор-исполнитель НЕ может закрыть сам себя (allow_self_close=0)',
    !$res['success'],
    (string) $res['message']
);

// 9. Журнал переходов.
$logs = $modx->getCount(MxBoardLog::class, ['task_id' => $taskId]);
check('журнал переходов пишется', $logs >= 4, "записей: {$logs}");

$closeLog = $modx->getObject(MxBoardLog::class, ['task_id' => $taskId, 'action' => 'close']);
check('закрытие записано в журнал с автором', $closeLog && (int) $closeLog->get('user_id') === (int) $author->get('id'));

// Уборка тестовых данных.
foreach ([$taskId, $selfId] as $id) {
    if ($id && ($t = $modx->getObject(MxBoardTask::class, $id))) {
        $t->remove();
    }
}
foreach (['mxb_test_author', 'mxb_test_worker', 'mxb_test_worker2'] as $u) {
    if ($obj = $modx->getObject(modUser::class, ['username' => $u])) {
        $obj->remove();
    }
}

echo "\n== Итог: {$pass} прошло, {$fail} упало ==\n";
exit($fail > 0 ? 1 : 0);
