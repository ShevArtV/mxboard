<?php

/**
 * Smoke удаления карточек через MCP (in-process, как остальные смоуки проекта).
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _delete_smoke_remote.php
 *
 * Что проверяем:
 *   — негативы: несуществующая карточка, чужая карточка, закрытая карточка;
 *   — позитив: карточка уходит вместе с комментариями, вложениями и уведомлениями;
 *   — подзадачи открепляются (parent_id = 0), а не удаляются;
 *   — журнал: запись action=delete с task_id = 0 от вызвавшего пользователя, канал mcp;
 *   — подзадаче дополнительно пишется subtask_delete в журнал родителя;
 *   — уведомление типа delete получают автор и исполнитель, task_id = 0;
 *   — менеджер отдела удаляет чужую карточку;
 *   — очередь: удаление ЗАНЯВШЕЙ очередь карточки двигает следующую в стартовую стадию,
 *     удаление карточки из начальной стадии очередь не трогает.
 *
 * Тестовые данные создаются в отдельном проекте `dsmoke` и убираются за собой.
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Mcp\Server;
use MxBoard\Model\MxBoardAttachment;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardNotification;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardQueue;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbdeletesmoke');
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

$P = 'MxBoard\\Processors\\Mgr\\';

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

/** @return array{0: bool, 1: mixed, 2: string} */
function run(modX $modx, modUser $user, string $action, array $props = []): array
{
    $modx->user = $user;
    $response = $modx->runProcessor($action, $props);
    if (!$response) {
        return [false, null, 'runProcessor вернул false (класс не найден?)'];
    }
    $raw = $response->getResponse();
    $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($arr)) {
        return [false, null, 'нераспарсиваемый ответ'];
    }

    return [(bool) ($arr['success'] ?? false), $arr['object'] ?? null, (string) ($arr['message'] ?? '')];
}

/**
 * Вызов MCP-инструмента. Возвращает [ошибка ли, текст ответа].
 *
 * @return array{0: bool, 1: string}
 */
function mcp(Server $server, string $tool, array $args): array
{
    $r = $server->handle([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $tool, 'arguments' => $args],
    ]);

    return [
        !empty($r['result']['isError']),
        (string) ($r['result']['content'][0]['text'] ?? ''),
    ];
}

function ensureUser(modX $modx, string $username): modUser
{
    /** @var modUser|null $user */
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

function ensureMember(modX $modx, int $groupId, int $userId, int $roleId): void
{
    /** @var modUserGroupMember|null $m */
    $m = $modx->getObject(modUserGroupMember::class, ['user_group' => $groupId, 'member' => $userId]);
    if (!$m) {
        $m = $modx->newObject(modUserGroupMember::class);
        $m->fromArray(['user_group' => $groupId, 'member' => $userId, 'rank' => 0]);
    }
    $m->set('role', $roleId);
    $m->save();
}

echo "== mxBoard: smoke удаления карточек ==\n";

/** @var MxBoardProject|null $base */
$base = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
if (!$base) {
    fwrite(STDERR, "нет проекта default — пакет не установлен?\n");
    exit(1);
}
$department = $modx->getObject(MxBoardDepartment::class, (int) $base->get('department_id'));
$departmentId = (int) $department->get('id');
$usergroupId = (int) $department->get('usergroup_id');

// Порог «супер группы отдела»: роль authority=1 считается менеджерской.
$modx->setOption('mxboard.group_admin_authority', 1);
if ($setting = $modx->getObject(modSystemSetting::class, 'mxboard.group_admin_authority')) {
    $setting->set('value', '1');
    $setting->save();
    $modx->getCacheManager()->refresh(['system_settings' => []]);
}
// Уведомления должны писаться: их наличие — часть проверки.
$modx->setOption('mxboard.sse_enabled', true);

$c = $modx->newQuery(modUserGroupRole::class);
$c->where(['authority:<=' => 1, 'authority:>' => 0]);
$c->sortby('authority', 'ASC');
/** @var modUserGroupRole|null $role */
$role = $modx->getObject(modUserGroupRole::class, $c);
if (!$role) {
    $role = $modx->newObject(modUserGroupRole::class);
    $role->fromArray(['name' => 'mxb-smoke-admin', 'authority' => 1]);
    $role->save();
}
$roleId = (int) $role->get('id');

$author = ensureUser($modx, 'mxb_d_author');
$worker = ensureUser($modx, 'mxb_d_worker');
$mgr = ensureUser($modx, 'mxb_d_mgr');
ensureMember($modx, $usergroupId, (int) $author->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $worker->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $mgr->get('id'), $roleId);

$authorId = (int) $author->get('id');
$workerId = (int) $worker->get('id');

$authorMcp = new Server($modx, $author);
$workerMcp = new Server($modx, $worker);
$mgrMcp = new Server($modx, $mgr);

// Проект: начальная / стартовая / финальная стадии. Двигать в стартовую разрешено
// автору и исполнителю — иначе автозапуск очереди упрётся в права, а не в логику.
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Project\\Create', [
    'department_id' => $departmentId,
    'key' => 'dsmoke',
    'name' => 'Удаление: смоук',
    'columns' => json_encode([
        ['key' => 'backlog', 'name' => 'Бэклог', 'is_initial' => 1, 'is_final' => 0, 'move_roles' => 'author,assignee'],
        ['key' => 'work', 'name' => 'В работе', 'is_initial' => 0, 'is_final' => 0, 'is_start' => 1, 'move_roles' => 'author,assignee'],
        ['key' => 'done', 'name' => 'Готово', 'is_initial' => 0, 'is_final' => 1, 'move_roles' => 'author'],
    ], JSON_UNESCAPED_UNICODE),
]);
$projectId = (int) ($obj['id'] ?? 0);
check('проект dsmoke создан', $ok && $projectId > 0, $msg);
if ($projectId <= 0) {
    fwrite(STDERR, "не удалось создать проект — дальше смысла нет\n");
    exit(1);
}

[$ok, , $msg] = run($modx, $mgr, $P . 'Type\\Create', [
    'department_id' => $departmentId,
    'key' => 'dsmoke_type',
    'name' => 'Удаление: тип',
    'fields' => json_encode([['key' => 'what', 'label' => 'Что', 'type' => 'text', 'required' => 1]], JSON_UNESCAPED_UNICODE),
]);
check('тип dsmoke_type создан', $ok, $msg);

function makeTask(modX $modx, modUser $author, string $P, int $workerId, string $title, int $parentId = 0): int
{
    $props = [
        'project' => 'dsmoke', 'type' => 'dsmoke_type', 'title' => $title,
        'deadline' => time() + 7 * 86400, 'assignee_id' => $workerId,
        'fields' => ['what' => 'смоук удаления'],
    ];
    if ($parentId > 0) {
        $props['parent_id'] = $parentId;
    }
    [$ok, $obj] = run($modx, $author, $P . 'Task\\Create', $props);

    return $ok ? (int) ($obj['id'] ?? 0) : 0;
}

// --- Негативы -----------------------------------------------------------------
echo "== негативные сценарии ==\n";

[$isErr, $text] = mcp($authorMcp, 'task_delete', ['task_id' => '99999999']);
check('несуществующая карточка отклонена', $isErr, $text);
check('текст ошибки про отсутствие задачи', mb_stripos($text, 'не найдена') !== false, $text);

$foreign = makeTask($modx, $author, $P, $workerId, 'удаление: чужая карточка');
check('карточка для проверки прав создана', $foreign > 0);

[$isErr, $text] = mcp($workerMcp, 'task_delete', ['task_id' => (string) $foreign]);
check('чужую карточку исполнитель удалить не может', $isErr, $text);
check('текст ошибки про права', mb_stripos($text, 'автору') !== false, $text);
check('карточка на месте', $modx->getObject(MxBoardTask::class, $foreign) !== null);

$closed = makeTask($modx, $author, $P, $workerId, 'удаление: закрытая карточка');
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $closed, 'column' => 'done']);
check('карточка закрыта', $ok, $msg);
[$isErr, $text] = mcp($authorMcp, 'task_delete', ['task_id' => (string) $closed]);
check('закрытую карточку удалить нельзя', $isErr, $text);
check('текст ошибки про закрытую карточку', mb_stripos($text, 'закрыт') !== false, $text);
check('закрытая карточка на месте', $modx->getObject(MxBoardTask::class, $closed) !== null);

// --- Позитив: полное удаление -------------------------------------------------
echo "== удаление ошибочной карточки ==\n";

$main = makeTask($modx, $author, $P, $workerId, 'удаление: ошибочная карточка');
$child = makeTask($modx, $author, $P, $workerId, 'удаление: подзадача-сирота', $main);
check('карточка и подзадача созданы', $main > 0 && $child > 0);

/** @var MxBoardTask $mainTask */
$mainTask = $modx->getObject(MxBoardTask::class, $main);
$mainNum = (string) $mainTask->get('num');

[$ok, , $msg] = run($modx, $worker, $P . 'Task\\Comment', ['id' => $main, 'content' => 'комментарий к ошибочной карточке']);
check('комментарий добавлен', $ok, $msg);
$commentCount = (int) $modx->getCount(MxBoardComment::class, ['task_id' => $main]);
check('комментарий записан', $commentCount > 0, 'count=' . $commentCount);

// Запись вложения без физфайла: purge должен снять её и не упасть на отсутствующем файле.
/** @var MxBoardAttachment $att */
$att = $modx->newObject(MxBoardAttachment::class);
$att->fromArray([
    'task_id' => $main, 'comment_id' => 0, 'user_id' => $authorId,
    'name' => 'dsmoke.txt', 'path' => 'mxboard/dsmoke-not-exists.txt',
    'ext' => 'txt', 'size' => 10, 'createdon' => time(),
]);
$att->save();
check('вложение записано', (int) $modx->getCount(MxBoardAttachment::class, ['task_id' => $main]) === 1);

// Уведомления по карточке уже есть (создание/комментарий) — их не должно остаться.
$notifBefore = (int) $modx->getCount(MxBoardNotification::class, ['task_id' => $main]);
check('уведомления по карточке накопились', $notifBefore > 0, 'count=' . $notifBefore);

[$isErr, $text] = mcp($authorMcp, 'task_delete', ['task_id' => $mainNum !== '' ? $mainNum : (string) $main]);
check('автор удалил карточку по номеру', !$isErr, $text);
check('в ответе номер и предупреждение о необратимости',
    mb_stripos($text, 'удалена без возможности восстановления') !== false, $text);

check('карточки нет', $modx->getObject(MxBoardTask::class, $main) === null);
check('комментарии удалены', (int) $modx->getCount(MxBoardComment::class, ['task_id' => $main]) === 0);
check('записи вложений удалены', (int) $modx->getCount(MxBoardAttachment::class, ['task_id' => $main]) === 0);
check('уведомления удалённой карточки убраны', (int) $modx->getCount(MxBoardNotification::class, ['task_id' => $main]) === 0);

/** @var MxBoardTask|null $orphan */
$orphan = $modx->getObject(MxBoardTask::class, $child);
check('подзадача жива', $orphan !== null);
check('подзадача откреплена (parent_id = 0)', $orphan !== null && (int) $orphan->get('parent_id') === 0,
    $orphan ? 'parent_id=' . $orphan->get('parent_id') : '');

// Журнал: запись об удалении не может висеть на удалённой карточке — она с task_id = 0.
$c = $modx->newQuery(MxBoardLog::class);
$c->where(['task_id' => 0, 'action' => 'delete', 'note:LIKE' => '%' . $mainNum . '%']);
$c->sortby('id', 'DESC');
$c->limit(1);
/** @var MxBoardLog|null $log */
$log = $modx->getObject(MxBoardLog::class, $c);
check('журнал: запись об удалении есть', $log !== null);
check('журнал: от имени вызвавшего', $log !== null && (int) $log->get('user_id') === $authorId,
    $log ? 'user_id=' . $log->get('user_id') : '');
check('журнал: канал mcp', $log !== null && (string) $log->get('channel') === 'mcp',
    $log ? (string) $log->get('channel') : '');
check('журнал: в note номер и заголовок',
    $log !== null && mb_stripos((string) $log->get('note'), 'ошибочная карточка') !== false,
    $log ? (string) $log->get('note') : '');
check('журнал: зафиксирована стадия, из которой удалили',
    $log !== null && (string) $log->get('from_column') === 'backlog',
    $log ? (string) $log->get('from_column') : '');

// Уведомление об удалении — исполнителю (автор сам актор, себе не пишет).
$c = $modx->newQuery(MxBoardNotification::class);
$c->where(['user_id' => $workerId, 'type' => 'delete']);
$c->sortby('id', 'DESC');
$c->limit(1);
/** @var MxBoardNotification|null $notif */
$notif = $modx->getObject(MxBoardNotification::class, $c);
check('уведомление об удалении исполнителю есть', $notif !== null);
check('уведомление без адреса карточки (task_id = 0)', $notif !== null && (int) $notif->get('task_id') === 0,
    $notif ? 'task_id=' . $notif->get('task_id') : '');
$payload = $notif ? (array) json_decode((string) $notif->get('payload'), true) : [];
check('в payload номер и заголовок исчезнувшей карточки',
    ($payload['num'] ?? '') === $mainNum && mb_stripos((string) ($payload['title'] ?? ''), 'ошибочная карточка') !== false,
    json_encode($payload, JSON_UNESCAPED_UNICODE));

// --- Удаление подзадачи -------------------------------------------------------
echo "== удаление подзадачи ==\n";

$parent = makeTask($modx, $author, $P, $workerId, 'удаление: родитель');
$sub = makeTask($modx, $author, $P, $workerId, 'удаление: лишняя подзадача', $parent);
check('родитель и подзадача созданы', $parent > 0 && $sub > 0);

/** @var MxBoardTask $subTask */
$subTask = $modx->getObject(MxBoardTask::class, $sub);
$subNum = (string) $subTask->get('num');

[$isErr, $text] = mcp($authorMcp, 'task_delete', ['task_id' => (string) $sub]);
check('подзадача удалена', !$isErr, $text);

$c = $modx->newQuery(MxBoardLog::class);
$c->where(['task_id' => $parent, 'action' => 'subtask_delete']);
$c->sortby('id', 'DESC');
$c->limit(1);
/** @var MxBoardLog|null $parentLog */
$parentLog = $modx->getObject(MxBoardLog::class, $c);
check('журнал родителя: запись subtask_delete', $parentLog !== null);
check('журнал родителя: в note номер подзадачи',
    $parentLog !== null && mb_stripos((string) $parentLog->get('note'), $subNum) !== false,
    $parentLog ? (string) $parentLog->get('note') : '');

// --- Менеджер удаляет чужое ---------------------------------------------------
echo "== менеджер удаляет чужую карточку ==\n";

[$isErr, $text] = mcp($mgrMcp, 'task_delete', ['task_id' => (string) $foreign]);
check('менеджер отдела удалил чужую карточку', !$isErr, $text);
check('карточки нет', $modx->getObject(MxBoardTask::class, $foreign) === null);

// --- Очередь ------------------------------------------------------------------
echo "== очередь ==\n";

[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Queue\\Create', ['project_id' => $projectId, 'name' => 'Очередь удаления']);
$queueId = (int) ($obj['id'] ?? 0);
check('очередь создана', $ok && $queueId > 0, $msg);

$q1 = makeTask($modx, $author, $P, $workerId, 'очередь: занявшая');
$q2 = makeTask($modx, $author, $P, $workerId, 'очередь: следующая');
$q3 = makeTask($modx, $author, $P, $workerId, 'очередь: третья');
foreach ([$q1, $q2, $q3] as $id) {
    run($modx, $author, $P . 'Queue\\AddTask', ['task_id' => $id, 'queue_id' => $queueId]);
}

// Удаление карточки, которая ещё лежит в начальной стадии, очередь не двигает:
// она очередь не занимала.
[$isErr, $text] = mcp($authorMcp, 'task_delete', ['task_id' => (string) $q3]);
check('карточка очереди из начальной стадии удалена', !$isErr, $text);
/** @var MxBoardTask|null $q2Task */
$q2Task = $modx->getObject(MxBoardTask::class, $q2);
$q2Column = $q2Task ? $modx->getObject(MxBoardColumn::class, (int) $q2Task->get('column_id')) : null;
check('очередь не тронулась: следующая осталась в начальной стадии',
    $q2Column !== null && (string) $q2Column->get('key') === 'backlog',
    $q2Column ? (string) $q2Column->get('key') : 'нет колонки');

// А вот удаление карточки, занявшей очередь, — для очереди такой же уход работы,
// как закрытие: следующая обязана стартовать.
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $q1, 'column' => 'work']);
check('первая карточка очереди взята в работу', $ok, $msg);

[$isErr, $text] = mcp($authorMcp, 'task_delete', ['task_id' => (string) $q1]);
check('занявшая очередь карточка удалена', !$isErr, $text);

/** @var MxBoardTask|null $next */
$next = $modx->getObject(MxBoardTask::class, $q2);
$nextColumn = $next ? $modx->getObject(MxBoardColumn::class, (int) $next->get('column_id')) : null;
check('следующая карточка очереди уехала в стартовую стадию',
    $nextColumn !== null && (string) $nextColumn->get('key') === 'work',
    $nextColumn ? (string) $nextColumn->get('key') : 'нет колонки');

// --- Уборка -------------------------------------------------------------------
echo "== teardown ==\n";

foreach ($modx->getCollection(MxBoardTask::class, ['project_id' => $projectId]) as $task) {
    $task->remove();
}
foreach ($modx->getCollection(MxBoardQueue::class, ['project_id' => $projectId]) as $q) {
    $q->remove();
}
if ($type = $modx->getObject(MxBoardTaskType::class, ['key' => 'dsmoke_type'])) {
    foreach ($modx->getCollection(MxBoardField::class, ['task_type_id' => $type->get('id')]) as $f) {
        $f->remove();
    }
    $type->remove();
}
if ($project = $modx->getObject(MxBoardProject::class, ['key' => 'dsmoke'])) {
    foreach ($modx->getCollection(MxBoardColumn::class, ['project_id' => $project->get('id')]) as $col) {
        $col->remove();
    }
    $project->remove();
}
foreach (['mxb_d_author', 'mxb_d_worker', 'mxb_d_mgr'] as $username) {
    /** @var modUser|null $user */
    $user = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$user) {
        continue;
    }
    $uid = (int) $user->get('id');
    foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $uid]) as $m) {
        $m->remove();
    }
    foreach ($modx->getCollection(MxBoardTask::class, ['author_id' => $uid]) as $task) {
        $task->remove();
    }
    foreach ($modx->getCollection(MxBoardNotification::class, ['user_id' => $uid]) as $n) {
        $n->remove();
    }
    $user->remove();
}
// Записи журнала об удалении живут с task_id = 0 — за собой их тоже убираем.
foreach ($modx->getCollection(MxBoardLog::class, ['task_id' => 0, 'action' => 'delete']) as $l) {
    if (mb_stripos((string) $l->get('note'), 'удаление:') !== false || mb_stripos((string) $l->get('note'), 'очередь:') !== false) {
        $l->remove();
    }
}

echo "\nИтог: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
