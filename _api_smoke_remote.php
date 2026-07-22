<?php

/**
 * Smoke Фазы 2 (in-process): фильтрация tools/list и маршрутизация REST/MCP напрямую,
 * без HTTP — проверяем логику каналов и паритет прав. Транспорт+авторизацию проверяет
 * отдельный curl-смоук с локали (для него скрипт готовит и печатает креды).
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _api_smoke_remote.php
 * Тестовые сущности НЕ убирает (нужны для curl) — уборка в _api_teardown_remote.php.
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Mcp\Server;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardToken;
use MxBoard\Rest\Router;
use MxBoard\Service\BoardQuery;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbapismoke');
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

function ensureUser(modX $modx, string $username, string $password, bool $sudo): modUser
{
    /** @var modUser|null $user */
    $user = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$user) {
        $user = $modx->newObject(modUser::class);
        $user->set('username', $username);
        $profile = $modx->newObject(modUserProfile::class);
        $profile->set('email', $username . '@mxboard.test');
        $user->addOne($profile);
    }
    $user->set('active', 1);
    $user->set('sudo', $sudo);
    $user->set('password', $password);
    $user->save();

    return $user;
}

echo "== mxBoard API smoke (in-process) ==\n";

$worker = ensureUser($modx, 'mxb_api_worker', 'Worker!pass123', false);
$mgr = ensureUser($modx, 'mxb_api_mgr', 'Mgr!pass123', false);

$project = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
$departmentId = (int) $project->get('department_id');
$department = $modx->getObject(MxBoardDepartment::class, $departmentId);
$usergroupId = (int) $department->get('usergroup_id');

// Делаем mgr менеджером отдела через НАСТОЯЩИЙ механизм: членство в группе отдела с
// ролью authority=1 + дефолтный порог group_admin_authority=1. Флаг sudo через API не ставится
// (MODX защищает). Порог пишем в БД и сбрасываем кэш настроек — чтобы и curl-процесс увидел.
$modx->setOption('mxboard.group_admin_authority', 1);
if ($setting = $modx->getObject(modSystemSetting::class, 'mxboard.group_admin_authority')) {
    $setting->set('value', '1');
    $setting->save();
}
$modx->getCacheManager()->refresh(['system_settings' => []]);

$role = $modx->getObject(modUserGroupRole::class, ['name' => 'mxb-smoke-admin']);
if (!$role) {
    $role = $modx->newObject(modUserGroupRole::class);
    $role->fromArray(['name' => 'mxb-smoke-admin', 'authority' => 1]);
    $role->save();
}
if (!$modx->getObject(modUserGroupMember::class, ['user_group' => $usergroupId, 'member' => $mgr->get('id')])) {
    $member = $modx->newObject(modUserGroupMember::class);
    $member->fromArray([
        'user_group' => $usergroupId,
        'member' => (int) $mgr->get('id'),
        'role' => (int) $role->get('id'),
        'rank' => 0,
    ]);
    $member->save();
}
// worker — рядовой член отдела (role=0): чтобы его можно было назначить исполнителем.
if (!$modx->getObject(modUserGroupMember::class, ['user_group' => $usergroupId, 'member' => $worker->get('id')])) {
    $wm = $modx->newObject(modUserGroupMember::class);
    $wm->fromArray(['user_group' => $usergroupId, 'member' => (int) $worker->get('id'), 'role' => 0, 'rank' => 0]);
    $wm->save();
}
$workerId = (int) $worker->get('id');

// Пароль реально выставился (нужно для Basic-входа в curl-смоуке).
check('пароль worker проверяется passwordMatches', $worker->passwordMatches('Worker!pass123'));
check('пароль mgr проверяется passwordMatches', $mgr->passwordMatches('Mgr!pass123'));

// Токен для worker (Bearer в curl-смоуке).
$rawToken = bin2hex(random_bytes(24));
foreach ($modx->getCollection(MxBoardToken::class, ['user_id' => $worker->get('id')]) as $t) {
    $t->remove();
}
$token = $modx->newObject(MxBoardToken::class);
$token->fromArray([
    'user_id' => (int) $worker->get('id'),
    'name' => 'api-smoke',
    'token_hash' => hash('sha256', $rawToken),
    'active' => true,
    'createdon' => time(),
]);
$token->save();

$DEADLINE = time() + 7 * 86400;

// Обязательные поля типа берём из живой схемы, а не из захардкоженного списка: набор
// полей у bugfix на стенде меняется (2026-07: добавились environment и severity), и
// фиксированный массив тихо ронял create с «Заполнены не все обязательные поля типа»,
// утаскивая за собой все зависимые проверки.
$BUG = [];
foreach ((new BoardQuery($modx))->typeSchema($project, 'bugfix')['fields'] ?? [] as $f) {
    if ($f['required']) {
        $BUG[$f['key']] = 'smoke';
    }
}
check('схема типа bugfix прочитана (обязательных полей > 0)', $BUG !== [], implode(',', array_keys($BUG)));

/* --- MCP: фильтрация tools/list --------------------------------------------- */

$toolNames = static function (Server $s): array {
    $resp = $s->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $names = [];
    foreach ($resp['result']['tools'] ?? [] as $t) {
        $names[] = $t['name'];
    }
    return $names;
};

$workerMcp = new Server($modx, $worker);
$mgrMcp = new Server($modx, $mgr);

$wNames = $toolNames($workerMcp);
$mNames = $toolNames($mgrMcp);

check('MCP worker видит базовые тулы (task_create)', in_array('task_create', $wNames, true));
check('MCP worker НЕ видит структурные (type_create)', !in_array('type_create', $wNames, true));
check('MCP mgr видит структурные (type_create/project_create/department_register)',
    in_array('type_create', $mNames, true) && in_array('project_create', $mNames, true) && in_array('department_register', $mNames, true));

// MCP tools/call: project_list доступен, type_create — только менеджеру.
$call = static function (Server $s, string $name, array $args): array {
    return $s->handle(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $args]]);
};
$r = $call($workerMcp, 'project_list', []);
check('MCP project_list работает', empty($r['result']['isError']));

$r = $call($workerMcp, 'type_create', ['department_id' => $departmentId, 'key' => 'x', 'name' => 'X', 'fields' => [['key' => 'a', 'label' => 'A']]]);
check('MCP type_create отклонён для worker', !empty($r['result']['isError']));

$r = $call($mgrMcp, 'type_create', ['department_id' => $departmentId, 'key' => 'smoke_type', 'name' => 'Smoke', 'fields' => [['key' => 'note', 'label' => 'Заметка', 'required' => true]]]);
check('MCP type_create проходит у mgr', empty($r['result']['isError']), json_encode($r['result']['content'][0]['text'] ?? '', JSON_UNESCAPED_UNICODE));

/* --- REST: маршрутизация Router --------------------------------------------- */

$workerRest = new Router($modx, $worker);
$mgrRest = new Router($modx, $mgr);

$r = $workerRest->dispatch('GET', ['projects'], [], []);
check('REST GET /projects', $r['status'] === 200 && $r['body']['success']);

$r = $workerRest->dispatch('GET', ['board'], ['project' => 'default'], []);
check('REST GET /board', $r['status'] === 200 && isset($r['body']['data']['columns']));

// Списочные методы.
$r = $workerRest->dispatch('GET', ['departments'], [], []);
check('REST GET /departments', $r['status'] === 200 && is_array($r['body']['data']));
$r = $workerRest->dispatch('GET', ['departments', (string) $departmentId, 'users'], [], []);
check('REST GET /departments/{id}/users (worker в списке)', $r['status'] === 200
    && in_array($workerId, array_map(static fn ($u) => (int) $u['id'], $r['body']['data']), true));
$r = $workerRest->dispatch('GET', ['departments', (string) $departmentId, 'types'], [], []);
check('REST GET /departments/{id}/types', $r['status'] === 200 && count($r['body']['data']) >= 1);
$r = $workerRest->dispatch('GET', ['projects', (string) $projectId, 'columns'], [], []);
check('REST GET /projects/{id}/columns (4 стадии)', $r['status'] === 200 && count($r['body']['data']) === 4);

// create (исполнитель обязателен и из отдела) → get → comment
$r = $workerRest->dispatch('POST', ['tasks'], [], ['type' => 'bugfix', 'title' => 'REST smoke', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $workerId]);
check('REST POST /tasks создаёт (201)', $r['status'] === 201 && $r['body']['success'], (string) $r['body']['message']);
$taskId = (int) ($r['body']['data']['id'] ?? 0);
check('REST create: исполнитель проставлен', (int) ($r['body']['data']['assignee_id'] ?? 0) === $workerId);

$r = $workerRest->dispatch('POST', ['tasks'], [], ['type' => 'bugfix', 'title' => 'no assignee', 'deadline' => $DEADLINE, 'fields' => $BUG]);
check('REST POST /tasks без исполнителя → 400', $r['status'] === 400 && !$r['body']['success']);

$r = $workerRest->dispatch('GET', ['tasks', (string) $taskId], [], []);
check('REST GET /tasks/{id} с деталями', $r['status'] === 200 && (int) $r['body']['data']['id'] === $taskId);

$r = $workerRest->dispatch('POST', ['tasks', (string) $taskId, 'comment'], [], ['content' => 'из REST']);
check('REST POST /tasks/{id}/comment', $r['status'] === 200 && $r['body']['success'], (string) $r['body']['message']);

/* --- MCP task_update: дедлайн (регресс карточки #66) -------------------------- */

// Читаем deadlineon из БД, а не из объекта: xPDO кэширует экземпляры по PK, и
// getObject мог бы вернуть тот, который правил сервис, — тогда тест проверял бы
// сам себя, а не факт записи.
$deadlineOf = static function (modX $modx, int $id): int {
    $table = $modx->getTableName(MxBoardTask::class);
    $stmt = $modx->prepare("SELECT deadlineon FROM {$table} WHERE id = :id");
    $stmt->execute(['id' => $id]);

    return (int) ($stmt->fetchColumn() ?: 0);
};
$fieldsOf = static function (modX $modx, int $id): array {
    $table = $modx->getTableName(MxBoardTask::class);
    $stmt = $modx->prepare("SELECT fields FROM {$table} WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $decoded = json_decode((string) ($stmt->fetchColumn() ?: ''), true);

    return is_array($decoded) ? $decoded : [];
};

// worker создал карточку через REST → он автор, значит вправе её править.
$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'deadline' => '2026-12-31']);
check('MCP task_update: дедлайн YYYY-MM-DD принят', empty($r['result']['isError']),
    json_encode($r['result'] ?? $r['error'] ?? [], JSON_UNESCAPED_UNICODE));
check('MCP task_update: YYYY-MM-DD сохранён как дата, а не обрезан в (int)',
    $deadlineOf($modx, $taskId) === (int) strtotime('2026-12-31'),
    'в БД ' . $deadlineOf($modx, $taskId) . ', ожидали ' . (int) strtotime('2026-12-31'));

$unix = time() + 30 * 86400;
$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'deadline' => $unix]);
check('MCP task_update: unix timestamp принят', empty($r['result']['isError']),
    json_encode($r['result'] ?? $r['error'] ?? [], JSON_UNESCAPED_UNICODE));
check('MCP task_update: unix сохранён как есть', $deadlineOf($modx, $taskId) === $unix,
    'в БД ' . $deadlineOf($modx, $taskId) . ', ожидали ' . $unix);

// Мусор — это штатная ошибка канала (isError + текст лексикона), а НЕ JSON-RPC -32603.
$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'deadline' => 'не дата']);
check('MCP task_update: битый дедлайн → isError, без Internal error',
    !empty($r['result']['isError']) && !isset($r['error']),
    json_encode($r, JSON_UNESCAPED_UNICODE));
check('MCP task_update: битый дедлайн не затирает сохранённый', $deadlineOf($modx, $taskId) === $unix);

// Паритет с REST: тот же дедлайн строкой через PATCH /tasks/{id}.
$r = $workerRest->dispatch('PATCH', ['tasks', (string) $taskId], [], ['deadline' => '2027-01-15']);
check('REST PATCH /tasks/{id} с дедлайном не сломан', $r['status'] === 200 && $r['body']['success'],
    (string) ($r['body']['message'] ?? ''));
check('REST PATCH: дата сохранена корректно', $deadlineOf($modx, $taskId) === (int) strtotime('2027-01-15'));

/* --- MCP task_update: частичный fields patch (регресс карточки #67) ---------- */

$patchKey = (string) array_key_first($BUG);
$beforeFields = $fieldsOf($modx, $taskId);
$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'fields' => [$patchKey => 'patched']]);
$afterPartial = $fieldsOf($modx, $taskId);
check('MCP task_update: частичный fields patch принят', empty($r['result']['isError']),
    json_encode($r['result'] ?? $r['error'] ?? [], JSON_UNESCAPED_UNICODE));
check('MCP task_update: частичный patch меняет только переданный ключ',
    ($afterPartial[$patchKey] ?? null) === 'patched'
    && count(array_diff_key($beforeFields, $afterPartial)) === 0
    && count(array_diff_key($afterPartial, $beforeFields)) === 0,
    json_encode(['before' => $beforeFields, 'after' => $afterPartial], JSON_UNESCAPED_UNICODE));

$r = $call($workerMcp, 'task_update', [
    'task_id' => (string) $taskId,
    'title' => 'MCP full update',
    'priority' => 2, // валидное значение из справочника (см. проверки приоритета ниже)
    'fields' => array_replace($afterPartial, [$patchKey => 'full']),
]);
$afterFull = $fieldsOf($modx, $taskId);
check('MCP task_update: полный update с fields принят', empty($r['result']['isError']),
    json_encode($r['result'] ?? $r['error'] ?? [], JSON_UNESCAPED_UNICODE));
check('MCP task_update: полный update сохраняет fields', ($afterFull[$patchKey] ?? null) === 'full',
    json_encode($afterFull, JSON_UNESCAPED_UNICODE));

$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'fields' => [$patchKey => '']]);
check('MCP task_update: пустое required-поле остаётся ошибкой',
    !empty($r['result']['isError']) && !isset($r['error']),
    json_encode($r, JSON_UNESCAPED_UNICODE));
check('MCP task_update: ошибка fields не затирает сохранённое', ($fieldsOf($modx, $taskId)[$patchKey] ?? null) === 'full');

$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'fields' => ['__unknown_smoke__' => 'x']]);
check('MCP task_update: неизвестный fields-ключ → isError, без Internal error',
    !empty($r['result']['isError']) && !isset($r['error']),
    json_encode($r, JSON_UNESCAPED_UNICODE));
check('MCP task_update: неизвестный fields-ключ не затирает сохранённое', ($fieldsOf($modx, $taskId)[$patchKey] ?? null) === 'full');

$r = $workerRest->dispatch('PATCH', ['tasks', (string) $taskId], [], ['fields' => [$patchKey => 'rest-partial']]);
check('REST PATCH /tasks/{id}: fields остаются полным набором, не MCP-merge',
    count($BUG) <= 1 || ($r['status'] === 400 && !$r['body']['success']),
    (string) ($r['body']['message'] ?? ''));

$r = $workerRest->dispatch('PATCH', ['tasks', (string) $taskId], [], ['fields' => array_replace($afterFull, ['__unknown_smoke__' => 'x'])]);
check('REST PATCH /tasks/{id}: неизвестный fields-ключ даёт валидационную ошибку',
    $r['status'] === 400 && !$r['body']['success'],
    (string) ($r['body']['message'] ?? ''));

/* --- Приоритет строго из справочника (карточка #2607-90) -------------------- */

$priorityOf = static function (modX $modx, int $id): int {
    $table = $modx->getTableName(MxBoardTask::class);
    $stmt = $modx->prepare("SELECT priority FROM {$table} WHERE id = :id");
    $stmt->execute(['id' => $id]);

    return (int) ($stmt->fetchColumn() ?: 0);
};

// В MCP-схеме task_create/task_update priority объявлен enum'ом допустимых значений.
$createSchema = null;
$updateSchema = null;
foreach (($workerMcp->handle(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list'])['result']['tools'] ?? []) as $tdef) {
    if (($tdef['name'] ?? '') === 'task_create') {
        $createSchema = $tdef['inputSchema']['properties']->priority ?? null;
    }
    if (($tdef['name'] ?? '') === 'task_update') {
        $updateSchema = $tdef['inputSchema']['properties']->priority ?? null;
    }
}
check('MCP task_create: priority объявлен enum допустимых значений',
    is_array($createSchema) && isset($createSchema['enum']) && in_array(2, $createSchema['enum'], true) && !in_array(99, $createSchema['enum'], true),
    json_encode($createSchema, JSON_UNESCAPED_UNICODE));
check('MCP task_update: priority объявлен enum допустимых значений',
    is_array($updateSchema) && isset($updateSchema['enum']) && in_array(0, $updateSchema['enum'], true),
    json_encode($updateSchema, JSON_UNESCAPED_UNICODE));

// MCP task_update: приоритет вне справочника отклоняется, сохранённое не затирается.
$beforePriority = $priorityOf($modx, $taskId);
$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'priority' => 99]);
check('MCP task_update: приоритет 99 → isError, без Internal error',
    !empty($r['result']['isError']) && !isset($r['error']),
    json_encode($r, JSON_UNESCAPED_UNICODE));
check('MCP task_update: отклонённый приоритет не записан', $priorityOf($modx, $taskId) === $beforePriority,
    'в БД ' . $priorityOf($modx, $taskId) . ', ожидали ' . $beforePriority);

// Валидный приоритет из справочника — принимается и пишется.
$r = $call($workerMcp, 'task_update', ['task_id' => (string) $taskId, 'priority' => 3]);
check('MCP task_update: валидный приоритет 3 принят', empty($r['result']['isError']),
    json_encode($r['result'] ?? $r['error'] ?? [], JSON_UNESCAPED_UNICODE));
check('MCP task_update: валидный приоритет записан', $priorityOf($modx, $taskId) === 3,
    'в БД ' . $priorityOf($modx, $taskId));

// REST create: приоритет вне справочника → 400, а валидный → 201.
$r = $workerRest->dispatch('POST', ['tasks'], [], ['type' => 'bugfix', 'title' => 'bad priority', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $workerId, 'priority' => 99]);
check('REST POST /tasks с приоритетом вне справочника → 400', $r['status'] === 400 && !$r['body']['success'], (string) ($r['body']['message'] ?? ''));

$r = $workerRest->dispatch('POST', ['tasks'], [], ['type' => 'bugfix', 'title' => 'good priority', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $workerId, 'priority' => 2]);
check('REST POST /tasks с валидным приоритетом → 201', $r['status'] === 201 && $r['body']['success'], (string) ($r['body']['message'] ?? ''));
$goodPriorityTaskId = (int) ($r['body']['data']['id'] ?? 0);
check('REST create: приоритет из справочника записан', $goodPriorityTaskId > 0 && $priorityOf($modx, $goodPriorityTaskId) === 2);
if ($goodPriorityTaskId) {
    $workerRest->dispatch('DELETE', ['tasks', (string) $goodPriorityTaskId], [], []);
}

// Структура: worker запрещено, mgr можно.
$r = $workerRest->dispatch('POST', ['types'], [], ['department_id' => $departmentId, 'key' => 'y', 'name' => 'Y', 'fields' => [['key' => 'a', 'label' => 'A']]]);
check('REST POST /types отклонён для worker', $r['status'] === 400 && !$r['body']['success']);

$r = $mgrRest->dispatch('POST', ['projects'], [], ['department_id' => $departmentId, 'key' => 'smoke_proj', 'name' => 'Smoke proj']);
check('REST POST /projects проходит у mgr (из шаблона колонок)', $r['status'] === 201 && $r['body']['success'], (string) $r['body']['message']);

$r = $workerRest->dispatch('GET', ['nope'], [], []);
check('REST неизвестный маршрут → 404', $r['status'] === 404);

// Уборка созданных в этом смоуке задач (пользователей/токен оставляем для curl).
if ($taskId) {
    $workerRest->dispatch('DELETE', ['tasks', (string) $taskId], [], []);
}

echo "\n== Итог in-process: {$pass} прошло, {$fail} упало ==\n";
echo "CREDS worker_token={$rawToken}\n";
echo "CREDS worker_user=mxb_api_worker worker_pass=Worker!pass123\n";
echo "CREDS mgr_user=mxb_api_mgr mgr_pass=Mgr!pass123\n";
exit($fail > 0 ? 1 : 0);
