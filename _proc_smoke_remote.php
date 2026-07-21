<?php

/**
 * Smoke процессоров Mgr (Фаза 3a), in-process — как смоуки Фаз 1–2.
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _proc_smoke_remote.php
 *
 * Почему in-process, а не curl через connector.php: авторизация коннектора
 * (context ACL + токен сессии) — код ядра MODX, уже проверенный рабочим UI v1;
 * его ACL-кэш по HTTP капризен и к нашему коду отношения не имеет. Здесь тестируем
 * СВОЁ: связку процессор→сервис и права mxBoard. Пользователь подставляется в
 * $modx->user, процессор вызывается через $modx->runProcessor(FQCN, props) — тем же
 * механизмом резолвинга класса, что и коннектор.
 *
 * Права mxBoard в CLI работают штатно: sudo — по флагу; «менеджер отдела» — через
 * authority JOIN (без сессии), ровно как в бою. Тестовые данные за собой убирает.
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroup;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbprocsmoke');
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

/**
 * Вызвать процессор от имени пользователя. Возвращает [success(bool), object(mixed), message(string)].
 */
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

    return [
        (bool) ($arr['success'] ?? false),
        $arr['object'] ?? null,
        (string) ($arr['message'] ?? ''),
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
    if ($modx->getObject(modUserGroupMember::class, ['user_group' => $groupId, 'member' => $userId])) {
        return;
    }
    $m = $modx->newObject(modUserGroupMember::class);
    $m->fromArray(['user_group' => $groupId, 'member' => $userId, 'role' => $roleId, 'rank' => 0]);
    $m->save();
}

echo "== mxBoard: smoke процессоров Mgr (in-process) ==\n";

$project = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
if (!$project) {
    fwrite(STDERR, "нет проекта default — пакет не установлен?\n");
    exit(1);
}
$department = $modx->getObject(MxBoardDepartment::class, (int) $project->get('department_id'));
$departmentId = (int) $department->get('id');
$usergroupId = (int) $department->get('usergroup_id');

// Дефолтный порог «супер группы отдела»: роль authority=1 считается менеджерской.
$modx->setOption('mxboard.group_admin_authority', 1);
if ($setting = $modx->getObject(modSystemSetting::class, 'mxboard.group_admin_authority')) {
    $setting->set('value', '1');
    $setting->save();
    $modx->getCacheManager()->refresh(['system_settings' => []]);
}

$role = $modx->getObject(modUserGroupRole::class, ['name' => 'mxb-smoke-admin']);
if (!$role) {
    $role = $modx->newObject(modUserGroupRole::class);
    $role->fromArray(['name' => 'mxb-smoke-admin', 'authority' => 1]);
    $role->save();
}
$roleId = (int) $role->get('id');

$author = ensureUser($modx, 'mxb_proc_author');
$worker = ensureUser($modx, 'mxb_proc_worker');
$out = ensureUser($modx, 'mxb_proc_out');
$mgr = ensureUser($modx, 'mxb_proc_mgr');

ensureMember($modx, $usergroupId, (int) $author->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $worker->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $mgr->get('id'), $roleId);

// Отдельная группа для проверки Register: mgr — её супер.
$group2 = $modx->getObject(modUserGroup::class, ['name' => 'mxb_proc_grp']);
if (!$group2) {
    $group2 = $modx->newObject(modUserGroup::class);
    $group2->set('name', 'mxb_proc_grp');
    $group2->save();
}
$group2Id = (int) $group2->get('id');
ensureMember($modx, $group2Id, (int) $mgr->get('id'), $roleId);

$workerId = (int) $worker->get('id');
$FIELDS = ['where' => 'стенд', 'what' => 'смоук', 'steps' => 'запустить', 'expected' => 'зелёный'];

// --- Списки -------------------------------------------------------------------
echo "== списки ==\n";
[$ok, $obj] = run($modx, $author, $P . 'Project\\GetList');
check('Project/GetList отдаёт default', $ok && in_array('default', array_column((array) $obj, 'key'), true));
[$ok, $obj] = run($modx, $author, $P . 'Department\\GetList');
check('Department/GetList отдаёт отдел', $ok && in_array($departmentId, array_map('intval', array_column((array) $obj, 'id')), true));
[$ok, $obj] = run($modx, $author, $P . 'Type\\GetList', ['department_id' => $departmentId]);
check('Type/GetList отдаёт bugfix', $ok && in_array('bugfix', array_column((array) $obj, 'key'), true));
[$ok, $obj, $msg] = run($modx, $author, $P . 'Type\\Schema', ['project' => 'default', 'type' => 'bugfix']);
check('Type/Schema: builtin + поля', $ok && !empty($obj['builtin']) && count($obj['fields'] ?? []) >= 4, $msg);
[$ok, $obj] = run($modx, $author, $P . 'Department\\Users', ['department_id' => $departmentId]);
check('Department/Users содержит worker', $ok && in_array('mxb_proc_worker', array_column((array) $obj, 'username'), true));
[$ok, $obj] = run($modx, $mgr, $P . 'Column\\GetList', ['project_id' => (int) $project->get('id')]);
check('Column/GetList: 4 стадии default', $ok && count((array) $obj) === 4);

// --- Задача: создание и видимость --------------------------------------------
echo "== задача: создание и видимость ==\n";
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'default', 'type' => 'bugfix', 'title' => 'proc-smoke: основная',
    'deadline' => time() + 7 * 86400, 'assignee_id' => $workerId, 'fields' => $FIELDS,
]);
$taskId = (int) ($obj['id'] ?? 0);
check('Task/Create автором (тип+дедлайн+исполнитель+поля)', $ok && $taskId > 0, $msg);
check('Task/Create: исполнитель проставлен', (int) ($obj['assignee_id'] ?? 0) === $workerId);

[$ok, , $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'default', 'type' => 'bugfix', 'title' => 'без дедлайна',
    'assignee_id' => $workerId, 'fields' => $FIELDS,
]);
check('Task/Create без дедлайна — отказ', !$ok, $msg);

[$ok] = run($modx, $out, $P . 'Task\\Get', ['id' => $taskId]);
check('Task/Get посторонним — отказ', !$ok);
[$ok, $obj] = run($modx, $worker, $P . 'Task\\Get', ['id' => $taskId]);
check('Task/Get исполнителем + журнал create', $ok && in_array('create', array_column($obj['log'] ?? [], 'action'), true));

$boardTaskIds = static function ($obj): array {
    $ids = [];
    foreach ($obj['columns'] ?? [] as $col) {
        foreach ($col['tasks'] ?? [] as $t) {
            $ids[] = (int) $t['id'];
        }
    }
    return $ids;
};
[$ok, $obj] = run($modx, $out, $P . 'Board\\Get', ['project' => 'default']);
check('Board/Get постороннему — без чужой карточки', $ok && !in_array($taskId, $boardTaskIds($obj), true));
[$ok, $obj] = run($modx, $mgr, $P . 'Board\\Get', ['project' => 'default']);
check('Board/Get менеджеру — карточка видна', $ok && in_array($taskId, $boardTaskIds($obj), true));
[$ok, $obj] = run($modx, $worker, $P . 'Board\\Get', ['project' => 'default', 'mine' => 1]);
check('Board/Get исполнителю (mine) — своя карточка видна', $ok && in_array($taskId, $boardTaskIds($obj), true));

// --- Переходы -----------------------------------------------------------------
echo "== переходы ==\n";
[$ok] = run($modx, $out, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'in_progress']);
check('Move посторонним — отказ', !$ok);
[$ok, , $msg] = run($modx, $worker, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'in_progress']);
check('Move исполнителем в in_progress', $ok, $msg);
[$ok, , $msg] = run($modx, $worker, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'review', 'note' => 'готово']);
check('Move исполнителем в review', $ok, $msg);
[$ok, , $msg] = run($modx, $worker, $P . 'Task\\Comment', ['id' => $taskId, 'content' => 'смоук-коммент']);
check('Comment исполнителем', $ok, $msg);

// --- Дедлайн ------------------------------------------------------------------
echo "== дедлайн ==\n";
[$ok] = run($modx, $author, $P . 'Task\\DisputeDeadline', ['id' => $taskId, 'proposed_date' => time() + 14 * 86400]);
check('Dispute автором — отказ (только исполнитель)', !$ok);
[$ok, $obj, $msg] = run($modx, $worker, $P . 'Task\\DisputeDeadline', ['id' => $taskId, 'proposed_date' => time() + 14 * 86400, 'reason' => 'не успеваю']);
check('Dispute исполнителем', $ok && (int) ($obj['deadline_disputed'] ?? 0) === 1, $msg);
$proposed = (int) ($obj['deadline_proposed'] ?? 0);
[$ok] = run($modx, $worker, $P . 'Task\\ResolveDeadline', ['id' => $taskId, 'accept' => 1]);
check('Resolve исполнителем — отказ (автор/менеджер)', !$ok);
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\ResolveDeadline', ['id' => $taskId, 'accept' => 1]);
check('Resolve автором: дедлайн = предложенный', $ok && (int) ($obj['deadlineon'] ?? 0) === $proposed, $msg);

// --- Подзадача блокирует закрытие --------------------------------------------
echo "== подзадача блокирует закрытие ==\n";
[$ok, $obj, $msg] = run($modx, $worker, $P . 'Task\\Create', [
    'project' => 'default', 'type' => 'bugfix', 'parent_id' => $taskId,
    'title' => 'proc-smoke: подзадача', 'deadline' => time() + 3 * 86400,
    'assignee_id' => $workerId, 'fields' => $FIELDS,
]);
$subId = (int) ($obj['id'] ?? 0);
check('Create подзадачи исполнителем родителя', $ok && $subId > 0, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'done']);
check('Закрытие родителя при открытой подзадаче — отказ', !$ok, $msg);
[$ok] = run($modx, $worker, $P . 'Task\\Move', ['id' => $subId, 'column' => 'done']);
check('Самозакрытие подзадачи (автор=исполнитель) — отказ', !$ok);
[$ok, , $msg] = run($modx, $mgr, $P . 'Task\\Move', ['id' => $subId, 'column' => 'done']);
check('Закрытие подзадачи менеджером', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'done']);
check('Закрытие родителя автором после подзадачи', $ok, $msg);

// --- Правка задач -------------------------------------------------------------
echo "== правка задач ==\n";
[$ok] = run($modx, $worker, $P . 'Task\\Update', ['id' => $taskId, 'title' => 'hack']);
check('Update исполнителем — отказ (автор/менеджер)', !$ok);
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Update', ['id' => $taskId, 'title' => 'proc-smoke: переим.', 'priority' => 2]);
check('Update автором', $ok && (int) ($obj['priority'] ?? 0) === 2, $msg);

// --- Структура: тип и поля ----------------------------------------------------
echo "== структура: тип и поля ==\n";
$tFields = [['key' => 'first', 'label' => 'Первое', 'type' => 'text', 'required' => true]];
[$ok] = run($modx, $author, $P . 'Type\\Create', ['department_id' => $departmentId, 'key' => 'smoke_ptype', 'name' => 'Смоук-тип', 'fields' => $tFields]);
check('Type/Create не-менеджером — отказ', !$ok);
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Type\\Create', ['department_id' => $departmentId, 'key' => 'smoke_ptype', 'name' => 'Смоук-тип', 'fields' => $tFields]);
$typeId = (int) ($obj['id'] ?? 0);
check('Type/Create менеджером', $ok && $typeId > 0, $msg);
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Field\\Create', ['task_type_id' => $typeId, 'key' => 'second', 'label' => 'Второе', 'type' => 'number']);
$f2 = (int) ($obj['id'] ?? 0);
check('Field/Create второе поле', $ok && $f2 > 0, $msg);
[$ok, $obj] = run($modx, $mgr, $P . 'Field\\GetList', ['task_type_id' => $typeId]);
$f1 = 0;
foreach ((array) $obj as $f) {
    if (($f['key'] ?? '') === 'first') {
        $f1 = (int) $f['id'];
    }
}
check('Field/GetList отдаёт оба поля с id', $ok && count((array) $obj) === 2 && $f1 > 0);
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Field\\Update', ['id' => $f2, 'label' => 'Второе (изм.)', 'required' => 1]);
check('Field/Update', $ok && (int) ($obj['required'] ?? 0) === 1, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Field\\Remove', ['id' => $f2]);
check('Field/Remove второго поля', $ok, $msg);
[$ok] = run($modx, $mgr, $P . 'Field\\Remove', ['id' => $f1]);
check('Field/Remove последнего — отказ (тип нерабочий)', !$ok);

// --- Структура: проект и колонки ----------------------------------------------
echo "== структура: проект и колонки ==\n";
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Project\\Create', ['department_id' => $departmentId, 'key' => 'smoke_pproj', 'name' => 'Смоук-проект']);
$projId = (int) ($obj['id'] ?? 0);
check('Project/Create из шаблона колонок', $ok && $projId > 0, $msg);
[$ok, $obj] = run($modx, $mgr, $P . 'Column\\GetList', ['project_id' => $projId]);
$cols = (array) $obj;
$initialCnt = count(array_filter($cols, static fn ($c) => !empty($c['is_initial'])));
$finalCnt = count(array_filter($cols, static fn ($c) => !empty($c['is_final'])));
$finalId = 0;
foreach ($cols as $c) {
    if (!empty($c['is_final'])) {
        $finalId = (int) $c['id'];
    }
}
check('Column/GetList: ровно одна initial и одна final', $ok && $initialCnt === 1 && $finalCnt === 1);
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Column\\Create', ['project_id' => $projId, 'key' => 'extra', 'name' => 'Доп. стадия', 'move_roles' => 'assignee']);
$colId = (int) ($obj['id'] ?? 0);
check('Column/Create', $ok && $colId > 0, $msg);
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Column\\Update', ['id' => $colId, 'is_final' => 1]);
check('Column/Update: перенос is_final', $ok && !empty($obj['is_final']), $msg);
[$ok, $obj] = run($modx, $mgr, $P . 'Column\\GetList', ['project_id' => $projId]);
check('после переноса — по-прежнему одна final', $ok && count(array_filter((array) $obj, static fn ($c) => !empty($c['is_final']))) === 1);
[$ok] = run($modx, $mgr, $P . 'Column\\Remove', ['id' => $colId]);
check('Remove носителя final — отказ', !$ok);
[$ok, , $msg] = run($modx, $mgr, $P . 'Column\\Update', ['id' => $finalId, 'is_final' => 1]);
check('перенос final назад на исходную', $ok, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Column\\Remove', ['id' => $colId]);
check('Column/Remove пустой обычной колонки', $ok, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Project\\Update', ['id' => $projId, 'name' => 'Смоук-проект (изм.)']);
check('Project/Update', $ok, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Project\\Remove', ['id' => $projId]);
check('Project/Remove пустого проекта', $ok, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Type\\Remove', ['id' => $typeId]);
check('Type/Remove без задач', $ok, $msg);

// --- Структура: отдел ---------------------------------------------------------
echo "== структура: отдел ==\n";
[$ok] = run($modx, $author, $P . 'Department\\Register', ['usergroup_id' => $group2Id]);
check('Register не-супером группы — отказ', !$ok);
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Department\\Register', ['usergroup_id' => $group2Id, 'name' => 'Смоук-отдел']);
$dept2 = (int) ($obj['id'] ?? 0);
check('Register супером группы', $ok && $dept2 > 0, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Department\\Update', ['id' => $dept2, 'name' => 'Смоук-отдел (изм.)']);
check('Department/Update', $ok, $msg);
[$ok, , $msg] = run($modx, $mgr, $P . 'Department\\Remove', ['id' => $dept2]);
check('Department/Remove пустого отдела', $ok, $msg);

// --- Удаление задач -----------------------------------------------------------
echo "== удаление задач ==\n";
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Remove', ['id' => $taskId]);
check('Task/Remove автором (подзадача открепляется)', $ok, $msg);
[$ok, $obj] = run($modx, $worker, $P . 'Task\\Get', ['id' => $subId]);
check('подзадача жива и без родителя', $ok && (int) ($obj['parent_id'] ?? -1) === 0);
[$ok, , $msg] = run($modx, $worker, $P . 'Task\\Remove', ['id' => $subId]);
check('Task/Remove подзадачи её автором', $ok, $msg);

// --- Уборка -------------------------------------------------------------------
echo "== teardown ==\n";
foreach (['mxb_proc_author', 'mxb_proc_worker', 'mxb_proc_out', 'mxb_proc_mgr'] as $u) {
    /** @var modUser|null $usr */
    $usr = $modx->getObject(modUser::class, ['username' => $u]);
    if (!$usr) {
        continue;
    }
    $uid = (int) $usr->get('id');
    foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $uid]) as $m) {
        $m->remove();
    }
    foreach ($modx->getCollection(MxBoardTask::class, ['author_id' => $uid]) as $t) {
        $t->remove();
    }
    $usr->remove();
}
if ($t = $modx->getObject(MxBoardTaskType::class, ['key' => 'smoke_ptype'])) {
    foreach ($modx->getCollection(MxBoardField::class, ['task_type_id' => $t->get('id')]) as $f) {
        $f->remove();
    }
    $t->remove();
}
if ($pj = $modx->getObject(MxBoardProject::class, ['key' => 'smoke_pproj'])) {
    foreach ($modx->getCollection(MxBoardColumn::class, ['project_id' => $pj->get('id')]) as $c) {
        $c->remove();
    }
    $pj->remove();
}
if ($g = $modx->getObject(modUserGroup::class, ['name' => 'mxb_proc_grp'])) {
    if ($d = $modx->getObject(MxBoardDepartment::class, ['usergroup_id' => $g->get('id')])) {
        $d->remove();
    }
    foreach ($modx->getCollection(modUserGroupMember::class, ['user_group' => $g->get('id')]) as $m) {
        $m->remove();
    }
    $g->remove();
}
if ($setting = $modx->getObject(modSystemSetting::class, 'mxboard.group_admin_authority')) {
    $setting->set('value', '1');
    $setting->save();
    $modx->getCacheManager()->refresh(['system_settings' => []]);
}

echo "\nИтог: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
