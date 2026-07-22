<?php

/**
 * Smoke очередей задач (in-process, как остальные смоуки проекта).
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _queue_smoke_remote.php
 *
 * Что проверяем:
 *   — CRUD очередей и права (не-менеджер не управляет очередями);
 *   — постановку задачи в очередь только из начальной стадии;
 *   — авто-выбор единственной очереди проекта;
 *   — порядок: reorder и promote (старт не с первой задачи);
 *   — АВТОЗАПУСК: закрытие задачи очереди двигает следующую в стартовую стадию
 *     от имени её автора, штатным move() (журнал канала `queue` + события);
 *   — ключ `queue` в payload события mxbOnTaskMove и в ленте /events.
 *
 * Тестовые данные создаются в отдельном проекте `qsmoke` и убираются за собой.
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardQueue;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
use MxBoard\Service\BoardQuery;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbqueuesmoke');
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
    // Роль выставляем всегда: членство могло остаться от прошлого прогона с другой ролью,
    // и тогда «менеджер» смоука молча оказался бы обычным участником.
    $m->set('role', $roleId);
    $m->save();
}

echo "== mxBoard: smoke очередей задач ==\n";

/** @var MxBoardProject|null $base */
$base = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
if (!$base) {
    fwrite(STDERR, "нет проекта default — пакет не установлен?\n");
    exit(1);
}
$department = $modx->getObject(MxBoardDepartment::class, (int) $base->get('department_id'));
$departmentId = (int) $department->get('id');
$usergroupId = (int) $department->get('usergroup_id');

// Порог «супер группы отдела»: роль authority=1 считается менеджерской. Настройку
// правим и в базе — getOption читает системный кэш, одного setOption мало.
$modx->setOption('mxboard.group_admin_authority', 1);
if ($setting = $modx->getObject(modSystemSetting::class, 'mxboard.group_admin_authority')) {
    $setting->set('value', '1');
    $setting->save();
    $modx->getCacheManager()->refresh(['system_settings' => []]);
}

// Менеджерская роль: берём существующую с authority <= порога (на стенде это
// «Board Manager»), а если такой нет — заводим свою.
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

$author = ensureUser($modx, 'mxb_q_author');
$worker = ensureUser($modx, 'mxb_q_worker');
$mgr = ensureUser($modx, 'mxb_q_mgr');
ensureMember($modx, $usergroupId, (int) $author->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $worker->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $mgr->get('id'), $roleId);

$authorId = (int) $author->get('id');
$workerId = (int) $worker->get('id');

// Свой проект со всеми тремя ролями стадий: начальная / стартовая / финальная.
// В стартовую двигать разрешаем автору и исполнителю (move_roles), иначе автозапуск
// от имени автора упрётся в права — и это была бы не проверка очереди, а проверка ACL.
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Project\\Create', [
    'department_id' => $departmentId,
    'key' => 'qsmoke',
    'name' => 'Очереди: смоук',
    'columns' => json_encode([
        ['key' => 'backlog', 'name' => 'Бэклог', 'is_initial' => 1, 'is_final' => 0, 'move_roles' => 'author,assignee'],
        ['key' => 'work', 'name' => 'В работе', 'is_initial' => 0, 'is_final' => 0, 'is_start' => 1, 'move_roles' => 'author,assignee'],
        ['key' => 'done', 'name' => 'Готово', 'is_initial' => 0, 'is_final' => 1, 'move_roles' => 'author'],
    ], JSON_UNESCAPED_UNICODE),
]);
$projectId = (int) ($obj['id'] ?? 0);
check('проект qsmoke создан', $ok && $projectId > 0, $msg);
if ($projectId <= 0) {
    fwrite(STDERR, "не удалось создать проект — дальше смысла нет\n");
    exit(1);
}

// Тип задачи с одним полем — «рабочий» тип обязан иметь ≥1 поле.
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Type\\Create', [
    'department_id' => $departmentId,
    'key' => 'qsmoke_type',
    'name' => 'Очереди: тип',
    'fields' => json_encode([['key' => 'what', 'label' => 'Что', 'type' => 'text', 'required' => 1]], JSON_UNESCAPED_UNICODE),
]);
check('тип qsmoke_type создан', $ok, $msg);

function makeTask(modX $modx, modUser $author, string $P, int $workerId, string $title): int
{
    [$ok, $obj] = run($modx, $author, $P . 'Task\\Create', [
        'project' => 'qsmoke', 'type' => 'qsmoke_type', 'title' => $title,
        'deadline' => time() + 7 * 86400, 'assignee_id' => $workerId,
        'fields' => ['what' => 'смоук очередей'],
    ]);

    return $ok ? (int) ($obj['id'] ?? 0) : 0;
}

// --- CRUD очередей и права ----------------------------------------------------
echo "== CRUD очередей и права ==\n";

[$ok, $obj, $msg] = run($modx, $worker, $P . 'Queue\\Create', ['project_id' => $projectId, 'name' => 'Чужая']);
check('Queue/Create не-менеджером отклонён', !$ok, $msg);

[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Queue\\Create', [
    'project_id' => $projectId, 'name' => 'Основная очередь', 'description' => 'смоук',
]);
$queueId = (int) ($obj['id'] ?? 0);
check('Queue/Create менеджером', $ok && $queueId > 0, $msg);
check('ключ сгенерирован из названия', ($obj['key'] ?? '') !== '', 'key=' . ($obj['key'] ?? ''));

[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Queue\\Update', ['id' => $queueId, 'name' => 'Основная очередь (изм.)']);
check('Queue/Update менеджером', $ok && ($obj['name'] ?? '') === 'Основная очередь (изм.)', $msg);

[$ok, $obj, $msg] = run($modx, $worker, $P . 'Queue\\Update', ['id' => $queueId, 'name' => 'взлом']);
check('Queue/Update не-менеджером отклонён', !$ok, $msg);

[$ok, $obj, $msg] = run($modx, $author, $P . 'Queue\\GetList', ['project_id' => $projectId]);
check('Queue/GetList отдаёт очередь', $ok && count((array) $obj) === 1, $msg);

// --- Постановка задач в очередь -----------------------------------------------
echo "== постановка задач в очередь ==\n";

$t1 = makeTask($modx, $author, $P, $workerId, 'очередь: первая');
$t2 = makeTask($modx, $author, $P, $workerId, 'очередь: вторая');
$t3 = makeTask($modx, $author, $P, $workerId, 'очередь: третья');
check('три задачи созданы', $t1 > 0 && $t2 > 0 && $t3 > 0);

// queue_id = 0 — «в единственную очередь проекта».
[$ok, $obj, $msg] = run($modx, $author, $P . 'Queue\\AddTask', ['task_id' => $t1]);
check('AddTask без queue_id: единственная очередь выбрана сама', $ok && (int) ($obj['queue']['id'] ?? 0) === $queueId, $msg);

[$ok, , $msg] = run($modx, $author, $P . 'Queue\\AddTask', ['task_id' => $t2, 'queue_id' => $queueId]);
check('AddTask второй задачи', $ok, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Queue\\AddTask', ['task_id' => $t3, 'queue_id' => $queueId]);
check('AddTask третьей задачи менеджером', $ok, $msg);

// Задача не из начальной стадии в очередь не идёт.
$tMoved = makeTask($modx, $author, $P, $workerId, 'очередь: уже в работе');
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $tMoved, 'column' => 'work']);
check('задача переведена в work', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Queue\\AddTask', ['task_id' => $tMoved, 'queue_id' => $queueId]);
check('AddTask не из начальной стадии отклонён', !$ok, $msg);

// --- Порядок ------------------------------------------------------------------
echo "== порядок в очереди ==\n";

[$ok, $obj, $msg] = run($modx, $author, $P . 'Queue\\GetList', ['project_id' => $projectId, 'with_tasks' => 1]);
$tasks = array_column((array) ($obj[0]['tasks'] ?? []), 'id');
check('порядок = порядку добавления', $ok && $tasks === [$t1, $t2, $t3], implode(',', $tasks));

[$ok, , $msg] = run($modx, $worker, $P . 'Queue\\Reorder', ['queue_id' => $queueId, 'order' => json_encode([$t3, $t2, $t1])]);
check('Reorder не-менеджером отклонён', !$ok, $msg);

[$ok, , $msg] = run($modx, $mgr, $P . 'Queue\\Reorder', ['queue_id' => $queueId, 'order' => json_encode([$t3, $t1, $t2])]);
check('Reorder менеджером', $ok, $msg);
[$ok, $obj] = run($modx, $author, $P . 'Queue\\GetList', ['project_id' => $projectId, 'with_tasks' => 1]);
$tasks = array_column((array) ($obj[0]['tasks'] ?? []), 'id');
check('новый порядок сохранён', $tasks === [$t3, $t1, $t2], implode(',', $tasks));

[$ok, , $msg] = run($modx, $mgr, $P . 'Queue\\Reorder', ['queue_id' => $queueId, 'order' => json_encode([$t3, $t1])]);
check('Reorder неполным списком отклонён', !$ok, $msg);

// Promote: стартуем не с первой задачи — она встаёт первой, остальные сдвигаются.
[$ok, , $msg] = run($modx, $author, $P . 'Queue\\Promote', ['task_id' => $t1]);
check('Promote автором задачи', $ok, $msg);
[$ok, $obj] = run($modx, $author, $P . 'Queue\\GetList', ['project_id' => $projectId, 'with_tasks' => 1]);
$tasks = array_column((array) ($obj[0]['tasks'] ?? []), 'id');
check('после Promote задача первая', $tasks === [$t1, $t3, $t2], implode(',', $tasks));

// --- Ключ queue в событиях ----------------------------------------------------
echo "== ключ queue в событиях ==\n";

// Плагина, который поймал бы invokeEvent, в CLI нет, поэтому проверяем контракт
// ленты /events — именно её, а не события MODX, читает внешняя автоматизация.
$lastLogId = (new BoardQuery($modx))->latestLogId();

[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $t1, 'column' => 'work']);
check('первая задача очереди переведена в стартовую стадию', $ok, $msg);

$events = (new BoardQuery($modx))->events($lastLogId, 50);
$moveEvent = null;
foreach ($events['events'] as $event) {
    if ((int) $event['task_id'] === $t1 && $event['action'] === 'move') {
        $moveEvent = $event;
    }
}
check('лента /events содержит ключ queue', $moveEvent !== null && (int) ($moveEvent['queue'] ?? 0) === $queueId,
    $moveEvent ? 'queue=' . var_export($moveEvent['queue'] ?? null, true) : 'события move нет');

// --- Автозапуск следующей задачи ----------------------------------------------
echo "== автозапуск следующей задачи ==\n";

$before = (new BoardQuery($modx))->latestLogId();

[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $t1, 'column' => 'done']);
check('первая задача закрыта автором', $ok, $msg);

/** @var MxBoardTask|null $next */
$next = $modx->getObject(MxBoardTask::class, $t3);
$nextColumn = $next ? $modx->getObject(MxBoardColumn::class, (int) $next->get('column_id')) : null;
check('следующая задача очереди уехала в стартовую стадию',
    $nextColumn !== null && (string) $nextColumn->get('key') === 'work',
    $nextColumn ? (string) $nextColumn->get('key') : 'нет колонки');

/** @var MxBoardTask|null $third */
$third = $modx->getObject(MxBoardTask::class, $t2);
$thirdColumn = $third ? $modx->getObject(MxBoardColumn::class, (int) $third->get('column_id')) : null;
check('третья задача осталась в начальной стадии',
    $thirdColumn !== null && (string) $thirdColumn->get('key') === 'backlog',
    $thirdColumn ? (string) $thirdColumn->get('key') : 'нет колонки');

// Автоперевод сделан от имени автора задачи и записан каналом `queue`.
$c = $modx->newQuery(MxBoardLog::class);
$c->where(['task_id' => $t3, 'action' => 'move', 'to_column' => 'work']);
$c->sortby('id', 'DESC');
$c->limit(1);
/** @var MxBoardLog|null $log */
$log = $modx->getObject(MxBoardLog::class, $c);
check('журнал: автозапуск от имени автора задачи', $log !== null && (int) $log->get('user_id') === $authorId,
    $log ? 'user_id=' . $log->get('user_id') : 'нет записи');
check('журнал: канал автозапуска = queue', $log !== null && (string) $log->get('channel') === 'queue',
    $log ? (string) $log->get('channel') : '');

$events = (new BoardQuery($modx))->events($before, 50);
$autoEvent = null;
foreach ($events['events'] as $event) {
    if ((int) $event['task_id'] === $t3 && $event['action'] === 'move') {
        $autoEvent = $event;
    }
}
check('автозапуск виден в ленте событий с ключом queue',
    $autoEvent !== null && (int) ($autoEvent['queue'] ?? 0) === $queueId,
    $autoEvent ? 'queue=' . var_export($autoEvent['queue'] ?? null, true) : 'события нет');

// Закрытая задача остаётся членом очереди — иначе её события потеряли бы ключ queue.
/** @var MxBoardTask|null $closed */
$closed = $modx->getObject(MxBoardTask::class, $t1);
check('закрытая задача осталась в очереди', $closed !== null && (int) $closed->get('queue_id') === $queueId);

// --- Вынуть из очереди --------------------------------------------------------
echo "== выход из очереди ==\n";

[$ok, , $msg] = run($modx, $author, $P . 'Queue\\RemoveTask', ['task_id' => $t2]);
check('RemoveTask автором', $ok, $msg);
/** @var MxBoardTask|null $outTask */
$outTask = $modx->getObject(MxBoardTask::class, $t2);
check('членство обнулено', $outTask !== null && (int) $outTask->get('queue_id') === 0);

// --- Удаление очереди ---------------------------------------------------------
echo "== удаление очереди ==\n";

[$ok, , $msg] = run($modx, $mgr, $P . 'Queue\\Remove', ['id' => $queueId]);
check('Queue/Remove менеджером', $ok, $msg);
check('очередь удалена', $modx->getObject(MxBoardQueue::class, $queueId) === null);
/** @var MxBoardTask|null $survivor */
$survivor = $modx->getObject(MxBoardTask::class, $t3);
check('задачи очереди живы, членство снято',
    $survivor !== null && (int) $survivor->get('queue_id') === 0);

// --- Уборка -------------------------------------------------------------------
echo "== teardown ==\n";

foreach ([$t1, $t2, $t3, $tMoved] as $id) {
    if ($id && ($task = $modx->getObject(MxBoardTask::class, $id))) {
        $task->remove();
    }
}
foreach ($modx->getCollection(MxBoardQueue::class, ['project_id' => $projectId]) as $q) {
    $q->remove();
}
if ($type = $modx->getObject(MxBoardTaskType::class, ['key' => 'qsmoke_type'])) {
    foreach ($modx->getCollection(MxBoardField::class, ['task_type_id' => $type->get('id')]) as $f) {
        $f->remove();
    }
    $type->remove();
}
if ($project = $modx->getObject(MxBoardProject::class, ['key' => 'qsmoke'])) {
    foreach ($modx->getCollection(MxBoardColumn::class, ['project_id' => $project->get('id')]) as $col) {
        $col->remove();
    }
    foreach ($modx->getCollection(MxBoardTask::class, ['project_id' => $project->get('id')]) as $task) {
        $task->remove();
    }
    $project->remove();
}
foreach (['mxb_q_author', 'mxb_q_worker', 'mxb_q_mgr'] as $username) {
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
    $user->remove();
}

echo "\nИтог: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
