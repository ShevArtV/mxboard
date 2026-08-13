<?php

/**
 * Smoke колонок под scope проекта (#2607-217), in-process, как остальные смоуки проекта.
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _column_scope_smoke_remote.php
 *
 * Что проверяем (везде — RAW `task.column_id` из БД, а не видимый `column_key`):
 *   — задача в проекте с собственными колонками получает колонку ЭТОГО проекта через
 *     все входы: mgr-процессор, REST POST /tasks, MCP task_create;
 *   — межпроектная подзадача получает начальную колонку СВОЕГО проекта (в обе стороны);
 *   — проект без собственных колонок продолжает жить на шаблоне (project_id = 0);
 *   — добавление колонки проекту на fallback материализует весь шаблон и переносит его
 *     задачи по ключу — на шаблонных строках не остаётся ни одной карточки;
 *   — copyColumns при наличии задач по-прежнему запрещён;
 *   — resetColumns возвращает проект с задачами на шаблон по ключу;
 *   — автозапуск очереди поднимает следующую задачу даже с «протухшим» шаблонным
 *     column_id (регресс #2607-153/#2607-160).
 *
 * Тестовые данные создаются в проектах `csmoke` / `csmoke2` и убираются за собой.
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Mcp\Server;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardQueue;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
use MxBoard\Rest\Router;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbcolsmoke');
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
$DEADLINE = time() + 7 * 86400;

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
    $m->set('role', $roleId);
    $m->save();
}

/**
 * RAW column_id задачи прямо из БД: xPDO кэширует экземпляры по PK, и getObject мог бы
 * вернуть тот объект, который правил сервис, — тогда тест проверял бы сам себя.
 *
 * @return array{0: int, 1: int, 2: string} [column_id, column.project_id, column.key]
 */
function rawColumn(modX $modx, int $taskId): array
{
    $prefix = (string) $modx->getOption('table_prefix');
    $sql = "SELECT c.id, c.project_id, c.`key`
            FROM {$prefix}mxboard_task t JOIN {$prefix}mxboard_column c ON c.id = t.column_id
            WHERE t.id = :id";
    $stmt = $modx->prepare($sql);
    $stmt->execute([':id' => $taskId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return $row ? [(int) $row['id'], (int) $row['project_id'], (string) $row['key']] : [0, -1, ''];
}

/** Сколько задач проекта стоит на колонках чужого scope. */
function driftCount(modX $modx, int $projectId, int $scope): int
{
    $prefix = (string) $modx->getOption('table_prefix');
    $sql = "SELECT COUNT(*) FROM {$prefix}mxboard_task t JOIN {$prefix}mxboard_column c ON c.id = t.column_id
            WHERE t.project_id = :pid AND c.project_id <> :scope";
    $stmt = $modx->prepare($sql);
    $stmt->execute([':pid' => $projectId, ':scope' => $scope]);

    return (int) $stmt->fetchColumn();
}

echo "== mxBoard: smoke колонок под scope проекта ==\n";

/** @var MxBoardProject|null $base */
$base = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
if (!$base) {
    fwrite(STDERR, "нет проекта default — пакет не установлен?\n");
    exit(1);
}
$department = $modx->getObject(MxBoardDepartment::class, (int) $base->get('department_id'));
$departmentId = (int) $department->get('id');
$usergroupId = (int) $department->get('usergroup_id');

$modx->setOption('mxboard.group_admin_authority', 1);
if ($setting = $modx->getObject(modSystemSetting::class, 'mxboard.group_admin_authority')) {
    $setting->set('value', '1');
    $setting->save();
    $modx->getCacheManager()->refresh(['system_settings' => []]);
}

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

$author = ensureUser($modx, 'mxb_c_author');
$worker = ensureUser($modx, 'mxb_c_worker');
$mgr = ensureUser($modx, 'mxb_c_mgr');
ensureMember($modx, $usergroupId, (int) $author->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $worker->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $mgr->get('id'), $roleId);
$authorId = (int) $author->get('id');
$workerId = (int) $worker->get('id');

// Шаблонные колонки (project_id = 0) — эталон fallback'а.
/** @var MxBoardColumn|null $tplInitial */
$tplInitial = $modx->getObject(MxBoardColumn::class, ['project_id' => 0, 'is_initial' => true]);
$tplInitialId = $tplInitial ? (int) $tplInitial->get('id') : 0;
$tplCount = (int) $modx->getCount(MxBoardColumn::class, ['project_id' => 0]);
check('шаблон колонок на месте (есть initial)', $tplInitialId > 0, 'колонок в шаблоне: ' . $tplCount);

// csmoke — проект СО СВОИМИ колонками; csmoke2 — на fallback (колонки не задаём).
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Project\\Create', [
    'department_id' => $departmentId,
    'key' => 'csmoke',
    'name' => 'Колонки: смоук',
    'columns' => json_encode([
        ['key' => 'backlog', 'name' => 'Бэклог', 'is_initial' => 1, 'is_final' => 0, 'move_roles' => 'author,assignee'],
        ['key' => 'work', 'name' => 'В работе', 'is_initial' => 0, 'is_final' => 0, 'is_start' => 1, 'move_roles' => 'author,assignee'],
        ['key' => 'done', 'name' => 'Готово', 'is_initial' => 0, 'is_final' => 1, 'move_roles' => 'author'],
    ], JSON_UNESCAPED_UNICODE),
]);
$ownProjectId = (int) ($obj['id'] ?? 0);
check('проект csmoke со своими колонками создан', $ok && $ownProjectId > 0, $msg);

[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Project\\Create', [
    'department_id' => $departmentId,
    'key' => 'csmoke2',
    'name' => 'Колонки: смоук fallback',
]);
$fbProjectId = (int) ($obj['id'] ?? 0);
check('проект csmoke2 на fallback создан', $ok && $fbProjectId > 0, $msg);
check('у csmoke2 нет своих колонок', (int) $modx->getCount(MxBoardColumn::class, ['project_id' => $fbProjectId]) === 0);

if ($ownProjectId <= 0 || $fbProjectId <= 0) {
    fwrite(STDERR, "не удалось создать проекты — дальше смысла нет\n");
    exit(1);
}

[$ok, , $msg] = run($modx, $mgr, $P . 'Type\\Create', [
    'department_id' => $departmentId,
    'key' => 'csmoke_type',
    'name' => 'Колонки: тип',
    'fields' => json_encode([['key' => 'what', 'label' => 'Что', 'type' => 'text', 'required' => 1]], JSON_UNESCAPED_UNICODE),
]);
check('тип csmoke_type создан', $ok, $msg);

/** @var int[] $created id всех созданных смоуком задач — для уборки */
$created = [];

/** @var MxBoardColumn|null $ownInitial */
$ownInitial = $modx->getObject(MxBoardColumn::class, ['project_id' => $ownProjectId, 'is_initial' => true]);
$ownInitialId = $ownInitial ? (int) $ownInitial->get('id') : 0;
check('у csmoke есть своя начальная стадия', $ownInitialId > 0);

/* --- Создание через все входы ------------------------------------------------ */
echo "== создание: колонка своего проекта при любом входе ==\n";

// 1. mgr-процессор (UI).
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'csmoke', 'type' => 'csmoke_type', 'title' => 'колонки: из UI',
    'deadline' => $DEADLINE, 'assignee_id' => $workerId, 'fields' => ['what' => 'смоук колонок'],
]);
$tUi = (int) ($obj['id'] ?? 0);
$created[] = $tUi;
[$cid, $cpid, $ckey] = rawColumn($modx, $tUi);
check('mgr-процессор: колонка проекта csmoke', $ok && $cid === $ownInitialId && $cpid === $ownProjectId,
    "column_id={$cid} project_id={$cpid} key={$ckey} — {$msg}");

// 2. REST POST /tasks.
$rest = new Router($modx, $author);
$r = $rest->dispatch('POST', ['tasks'], [], [
    'project' => 'csmoke', 'type' => 'csmoke_type', 'title' => 'колонки: из REST',
    'deadline' => $DEADLINE, 'assignee_id' => $workerId, 'fields' => ['what' => 'смоук колонок'],
]);
$tRest = (int) ($r['body']['data']['id'] ?? 0);
$created[] = $tRest;
[$cid, $cpid, $ckey] = rawColumn($modx, $tRest);
check('REST POST /tasks: колонка проекта csmoke', $r['status'] === 201 && $cid === $ownInitialId && $cpid === $ownProjectId,
    "column_id={$cid} project_id={$cpid} key={$ckey} — " . (string) ($r['body']['message'] ?? ''));

// 3. MCP task_create.
$mcp = new Server($modx, $author);
$call = static function (Server $s, string $name, array $args): array {
    return $s->handle(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
};
$r = $call($mcp, 'task_create', [
    'project' => 'csmoke', 'type' => 'csmoke_type', 'title' => 'колонки: из MCP',
    'deadline' => date('Y-m-d', $DEADLINE), 'assignee' => $workerId, 'fields' => ['what' => 'смоук колонок'],
]);
$mcpText = (string) ($r['result']['content'][0]['text'] ?? '');
/** @var MxBoardTask|null $mcpTask */
$mcpTask = $modx->getObject(MxBoardTask::class, ['project_id' => $ownProjectId, 'title' => 'колонки: из MCP']);
$tMcp = $mcpTask ? (int) $mcpTask->get('id') : 0;
$created[] = $tMcp;
[$cid, $cpid, $ckey] = rawColumn($modx, $tMcp);
check('MCP task_create: колонка проекта csmoke', empty($r['result']['isError']) && $cid === $ownInitialId && $cpid === $ownProjectId,
    "column_id={$cid} project_id={$cpid} key={$ckey} — {$mcpText}");

/* --- Fallback ---------------------------------------------------------------- */
echo "== fallback: проект без своих колонок ==\n";

[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'csmoke2', 'type' => 'csmoke_type', 'title' => 'колонки: fallback',
    'deadline' => $DEADLINE, 'assignee_id' => $workerId, 'fields' => ['what' => 'смоук колонок'],
]);
$tFb = (int) ($obj['id'] ?? 0);
$created[] = $tFb;
[$cid, $cpid, $ckey] = rawColumn($modx, $tFb);
check('проект без своих колонок берёт шаблон', $ok && $cid === $tplInitialId && $cpid === 0,
    "column_id={$cid} project_id={$cpid} key={$ckey} — {$msg}");

/* --- Межпроектные подзадачи --------------------------------------------------- */
echo "== межпроектные подзадачи ==\n";

// Родитель в csmoke (свои колонки) → подзадача в csmoke2 (fallback). Родителем берём
// задачу из MCP, а не $tUi: $tUi закрывается ниже в проверке автозапуска, а незакрытая
// подзадача блокировала бы закрытие родителя — это другой инвариант, не наш.
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'csmoke2', 'parent_id' => $tMcp, 'type' => 'csmoke_type', 'title' => 'колонки: подзадача в fallback',
    'deadline' => $DEADLINE, 'assignee_id' => $workerId, 'fields' => ['what' => 'смоук колонок'],
]);
$tSubFb = (int) ($obj['id'] ?? 0);
$created[] = $tSubFb;
[$cid, $cpid, $ckey] = rawColumn($modx, $tSubFb);
check('подзадача в fallback-проекте: колонка шаблона', $ok && $cid === $tplInitialId && $cpid === 0,
    "column_id={$cid} project_id={$cpid} key={$ckey} — {$msg}");

// Родитель в csmoke2 (fallback) → подзадача в csmoke (свои колонки).
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'csmoke', 'parent_id' => $tFb, 'type' => 'csmoke_type', 'title' => 'колонки: подзадача в csmoke',
    'deadline' => $DEADLINE, 'assignee_id' => $workerId, 'fields' => ['what' => 'смоук колонок'],
]);
$tSubOwn = (int) ($obj['id'] ?? 0);
$created[] = $tSubOwn;
[$cid, $cpid, $ckey] = rawColumn($modx, $tSubOwn);
check('подзадача в проекте со своими колонками: колонка проекта', $ok && $cid === $ownInitialId && $cpid === $ownProjectId,
    "column_id={$cid} project_id={$cpid} key={$ckey} — {$msg}");

/* --- Материализация шаблона при добавлении колонки ---------------------------- */
echo "== добавление колонки проекту на fallback ==\n";

// В csmoke2 уже есть задачи на шаблонных колонках — раньше добавление колонки
// оставляло их там (и проект оставался без initial/final).
$fbTasksBefore = (int) $modx->getCount(MxBoardTask::class, ['project_id' => $fbProjectId]);
check('в csmoke2 есть задачи до материализации', $fbTasksBefore > 0, 'задач: ' . $fbTasksBefore);

[$ok, , $msg] = run($modx, $mgr, $P . 'Column\\Create', [
    'project_id' => $fbProjectId, 'key' => 'backlog', 'name' => 'Дубль бэклога',
]);
check('колонка с ключом из шаблона отклонена', !$ok, $msg);

[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Column\\Create', [
    'project_id' => $fbProjectId, 'key' => 'extra', 'name' => 'Доп. стадия',
]);
check('колонка extra добавлена', $ok, $msg);

$fbOwn = (int) $modx->getCount(MxBoardColumn::class, ['project_id' => $fbProjectId]);
check('шаблон материализован целиком (+ новая колонка)', $fbOwn === $tplCount + 1, "своих колонок: {$fbOwn}, в шаблоне: {$tplCount}");
check('у csmoke2 появилась своя начальная стадия',
    $modx->getObject(MxBoardColumn::class, ['project_id' => $fbProjectId, 'is_initial' => true]) !== null);
check('у csmoke2 появилась своя финальная стадия',
    $modx->getObject(MxBoardColumn::class, ['project_id' => $fbProjectId, 'is_final' => true]) !== null);
check('ни одна задача csmoke2 не осталась на шаблоне', driftCount($modx, $fbProjectId, $fbProjectId) === 0,
    'осталось: ' . driftCount($modx, $fbProjectId, $fbProjectId));

[$cid, $cpid, $ckey] = rawColumn($modx, $tFb);
check('карточка переехала на одноимённую колонку проекта', $cpid === $fbProjectId && $ckey === 'backlog',
    "column_id={$cid} project_id={$cpid} key={$ckey}");

// Создание после материализации тоже даёт колонку проекта.
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'csmoke2', 'type' => 'csmoke_type', 'title' => 'колонки: после материализации',
    'deadline' => $DEADLINE, 'assignee_id' => $workerId, 'fields' => ['what' => 'смоук колонок'],
]);
$tAfter = (int) ($obj['id'] ?? 0);
$created[] = $tAfter;
[$cid, $cpid, $ckey] = rawColumn($modx, $tAfter);
check('новая задача csmoke2 — в колонке проекта', $ok && $cpid === $fbProjectId, "project_id={$cpid} — {$msg}");

/* --- copyColumns при наличии задач остаётся запрещён -------------------------- */
echo "== copyColumns и resetColumns ==\n";

[$ok, , $msg] = run($modx, $mgr, $P . 'Column\\Copy', ['project_id' => $ownProjectId, 'source_id' => 0]);
check('Column/Copy в проект с задачами отклонён', !$ok, $msg);

// resetColumns возвращает csmoke2 на шаблон, задачи — по ключу.
[$ok, , $msg] = run($modx, $mgr, $P . 'Column\\Reset', ['project_id' => $fbProjectId]);
check('Column/Reset вернул csmoke2 на шаблон', $ok, $msg);
check('после reset у csmoke2 нет своих колонок', (int) $modx->getCount(MxBoardColumn::class, ['project_id' => $fbProjectId]) === 0);
check('после reset задачи csmoke2 стоят на шаблоне', driftCount($modx, $fbProjectId, 0) === 0,
    'вне шаблона: ' . driftCount($modx, $fbProjectId, 0));
[$cid, $cpid, $ckey] = rawColumn($modx, $tFb);
check('после reset ключ стадии сохранён', $cpid === 0 && $ckey === 'backlog', "project_id={$cpid} key={$ckey}");

/* --- Автозапуск очереди при протухшем column_id ------------------------------- */
echo "== автозапуск очереди при шаблонном column_id ==\n";

[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Queue\\Create', ['project_id' => $ownProjectId, 'name' => 'Колонки: очередь']);
$queueId = (int) ($obj['id'] ?? 0);
check('очередь создана', $ok && $queueId > 0, $msg);

[$ok, , $msg] = run($modx, $author, $P . 'Queue\\AddTask', ['task_id' => $tUi, 'queue_id' => $queueId]);
check('первая задача в очереди', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Queue\\AddTask', ['task_id' => $tRest, 'queue_id' => $queueId]);
check('вторая задача в очереди', $ok, $msg);

// Портим данные ровно так, как это выглядело на живой доске: вторая задача очереди
// стоит на ШАБЛОННОМ backlog при собственных колонках проекта.
$prefix = (string) $modx->getOption('table_prefix');
$modx->exec("UPDATE {$prefix}mxboard_task SET column_id = {$tplInitialId} WHERE id = {$tRest}");
[$cid, $cpid] = rawColumn($modx, $tRest);
check('drift воспроизведён', $cid === $tplInitialId && $cpid === 0, "column_id={$cid} project_id={$cpid}");

[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $tUi, 'column' => 'work']);
check('первая задача переведена в стартовую стадию', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $tUi, 'column' => 'done']);
check('первая задача закрыта', $ok, $msg);

[$cid, $cpid, $ckey] = rawColumn($modx, $tRest);
check('автозапуск поднял задачу с шаблонным column_id', $ckey === 'work' && $cpid === $ownProjectId,
    "column_id={$cid} project_id={$cpid} key={$ckey}");
check('после автозапуска drift в csmoke устранён', driftCount($modx, $ownProjectId, $ownProjectId) === 0,
    'осталось: ' . driftCount($modx, $ownProjectId, $ownProjectId));

/* --- Самолечение на move() ---------------------------------------------------- */
echo "== самолечение колонки при переводе ==\n";

$modx->exec("UPDATE {$prefix}mxboard_task SET column_id = {$tplInitialId} WHERE id = {$tSubOwn}");
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $tSubOwn, 'column' => 'work']);
[$cid, $cpid, $ckey] = rawColumn($modx, $tSubOwn);
check('move() нормализовал колонку и выполнил переход', $ok && $ckey === 'work' && $cpid === $ownProjectId,
    "column_id={$cid} project_id={$cpid} key={$ckey} — {$msg}");

/* --- Уборка ------------------------------------------------------------------- */
echo "== teardown ==\n";

foreach ($created as $id) {
    if ($id && ($task = $modx->getObject(MxBoardTask::class, $id))) {
        $task->remove();
    }
}
foreach ([$ownProjectId, $fbProjectId] as $pid) {
    foreach ($modx->getCollection(MxBoardQueue::class, ['project_id' => $pid]) as $q) {
        $q->remove();
    }
    foreach ($modx->getCollection(MxBoardTask::class, ['project_id' => $pid]) as $task) {
        $task->remove();
    }
    foreach ($modx->getCollection(MxBoardColumn::class, ['project_id' => $pid]) as $col) {
        $col->remove();
    }
}
if ($type = $modx->getObject(MxBoardTaskType::class, ['key' => 'csmoke_type'])) {
    foreach ($modx->getCollection(MxBoardField::class, ['task_type_id' => $type->get('id')]) as $f) {
        $f->remove();
    }
    $type->remove();
}
foreach (['csmoke', 'csmoke2'] as $key) {
    if ($project = $modx->getObject(MxBoardProject::class, ['key' => $key])) {
        $project->remove();
    }
}
foreach (['mxb_c_author', 'mxb_c_worker', 'mxb_c_mgr'] as $username) {
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
