<?php

/**
 * Smoke-тест mxBoard v2 (слой данных) на стенде — модель «исполнитель назначается при
 * создании» (без захвата/пула).
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _smoke_remote.php
 *
 * Что проверяем:
 *   1. Сид: отдел, типы, проект default с 4 колонками (инварианты одна initial/одна final).
 *   2. Валидация create: без типа/дедлайна/поля/исполнителя — отказ; исполнитель не из
 *      отдела — отказ; валидно — ок.
 *   3. Исполнитель (assignee) двигает до review, но не закрывает; автор закрывает.
 *   4. Запрет самоаттестации (автор=исполнитель).
 *   5. Оспаривание дедлайна: reject не меняет, accept меняет.
 *   6. Подзадача блокирует финальную; закрытие подзадачи разблокирует.
 *   7. Видимость: посторонний не видит; соисполнитель подзадачи видит родителя (canView),
 *      но родитель НЕ в его канбане (boardCondition).
 *   8. Журнал (dispute, subtask_add).
 *
 * Тестовые данные за собой убирает.
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Helpers\Visibility;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
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
$createdTaskIds = [];

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

function joinDept(modX $modx, modUser $user, int $usergroupId): void
{
    if (!$modx->getObject(modUserGroupMember::class, ['member' => $user->get('id'), 'user_group' => $usergroupId])) {
        $m = $modx->newObject(modUserGroupMember::class);
        $m->fromArray(['user_group' => $usergroupId, 'member' => (int) $user->get('id'), 'role' => 0, 'rank' => 0]);
        $m->save();
    }
}

function driveToDone(TaskService $service, modUser $author, int $taskId): array
{
    $service->move($author, $taskId, 'in_progress', '', 'api');
    $service->move($author, $taskId, 'review', '', 'api');

    return $service->move($author, $taskId, 'done', '', 'api');
}

$DEADLINE = time() + 7 * 86400;
$BUG = ['where' => 'checkout', 'what' => 'падает', 'steps' => 'открыть корзину', 'expected' => 'не падает'];
$FEAT = ['goal' => 'ускорить', 'criteria' => 'p95 < 200ms'];

echo "== mxBoard v2 smoke (назначение при создании) ==\n";

$service = new TaskService($modx);

/* --- 1. Сид ---------------------------------------------------------------- */

$project = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
check('проект default существует', (bool) $project);
if (!$project) {
    exit(1);
}
$projectId = (int) $project->get('id');
$department = $modx->getObject(MxBoardDepartment::class, (int) $project->get('department_id'));
$usergroupId = (int) $department->get('usergroup_id');

$columns = $modx->getCollection(MxBoardColumn::class, ['project_id' => $projectId]);
check('у проекта 4 колонки', count($columns) === 4, 'найдено: ' . count($columns));
check('ровно одна initial', $modx->getCount(MxBoardColumn::class, ['project_id' => $projectId, 'is_initial' => true]) === 1);
check('ровно одна final', $modx->getCount(MxBoardColumn::class, ['project_id' => $projectId, 'is_final' => true]) === 1);

$bugType = $modx->getObject(MxBoardTaskType::class, ['key' => 'bugfix']);
check('тип bugfix есть', (bool) $bugType);
check('у bugfix ≥1 поле', $bugType && $modx->getCount(MxBoardField::class, ['task_type_id' => $bugType->get('id')]) >= 1);

// Автор — из другого отдела (кросс-отдел допустим); исполнители — члены отдела проекта.
$author = ensureUser($modx, 'mxb_test_author');
$worker = ensureUser($modx, 'mxb_test_worker');
$collab = ensureUser($modx, 'mxb_test_collab');
$stranger = ensureUser($modx, 'mxb_test_stranger');
joinDept($modx, $author, $usergroupId); // автор тоже член — для теста самоаттестации
joinDept($modx, $worker, $usergroupId);
joinDept($modx, $collab, $usergroupId);
// stranger намеренно НЕ в отделе.

$workerId = (int) $worker->get('id');

/* --- 2. Валидация create --------------------------------------------------- */

$res = $service->create($author, ['type' => 'bugfix', 'title' => 'нет исполнителя', 'deadline' => $DEADLINE, 'fields' => $BUG], 'api');
check('create без исполнителя отклонён', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['type' => 'bugfix', 'title' => 'чужой исполнитель', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $stranger->get('id')], 'api');
check('create с исполнителем НЕ из отдела отклонён', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['type' => 'bugfix', 'title' => 'нет дедлайна', 'fields' => $BUG, 'assignee_id' => $workerId], 'api');
check('create без дедлайна отклонён', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['type' => 'bugfix', 'title' => 'SMOKE bug', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $workerId], 'api');
check('create валидной задачи прошёл', $res['success'], (string) $res['message']);
$taskId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $taskId;
check('исполнитель проставлен при создании', (int) ($res['object']['assignee_id'] ?? 0) === $workerId);

/* --- 3. Движение и закрытие ------------------------------------------------ */

$res = $service->move($worker, $taskId, 'in_progress', '', 'mcp');
check('исполнитель двигает backlog→in_progress', $res['success'], (string) $res['message']);
$res = $service->move($worker, $taskId, 'review', 'готово', 'mcp');
check('исполнитель двигает до review', $res['success'], (string) $res['message']);
$res = $service->move($worker, $taskId, 'done', 'я всё', 'mcp');
check('исполнитель НЕ может закрыть', !$res['success'], (string) $res['message']);
$res = $service->move($author, $taskId, 'done', 'принято', 'mgr');
check('автор закрыл', $res['success'], (string) $res['message']);
check('closedon проставлен', (int) $modx->getObject(MxBoardTask::class, $taskId)->get('closedon') > 0);

/* --- 4. Самоаттестация ----------------------------------------------------- */

$res = $service->create($author, ['type' => 'feature', 'title' => 'SMOKE self', 'deadline' => $DEADLINE, 'fields' => $FEAT, 'assignee_id' => $author->get('id')], 'api');
$selfId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $selfId;
$service->move($author, $selfId, 'in_progress', '', 'mcp');
$service->move($author, $selfId, 'review', '', 'mcp');
$res = $service->move($author, $selfId, 'done', '', 'mcp');
check('автор-исполнитель НЕ может закрыть сам себя', !$res['success'], (string) $res['message']);

/* --- 5. Оспаривание дедлайна ----------------------------------------------- */

$res = $service->create($author, ['type' => 'bugfix', 'title' => 'SMOKE deadline', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $workerId], 'api');
$dlId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $dlId;
$newDate = $DEADLINE + 3 * 86400;
$service->disputeDeadline($worker, $dlId, $newDate, 'нужно больше', 'mcp');
check('флаг оспаривания выставлен', (bool) $modx->getObject(MxBoardTask::class, $dlId)->get('deadline_disputed'));
$res = $service->resolveDeadline($worker, $dlId, true, 'mgr');
check('исполнитель НЕ разрешает оспаривание', !$res['success'], (string) $res['message']);
$service->resolveDeadline($author, $dlId, false, 'mgr');
check('reject: дедлайн не изменился', (int) $modx->getObject(MxBoardTask::class, $dlId)->get('deadlineon') === $DEADLINE);
$service->disputeDeadline($worker, $dlId, $newDate, 'ещё раз', 'mcp');
$service->resolveDeadline($author, $dlId, true, 'mgr');
check('accept: дедлайн стал предложенным', (int) $modx->getObject(MxBoardTask::class, $dlId)->get('deadlineon') === $newDate);

/* --- 6. Подзадача-блокер + видимость --------------------------------------- */

$res = $service->create($author, ['type' => 'feature', 'title' => 'SMOKE parent', 'deadline' => $DEADLINE, 'fields' => $FEAT, 'assignee_id' => $workerId], 'api');
$parentId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $parentId;

$res = $service->create($stranger, ['type' => 'bugfix', 'title' => 'sub denied', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $collab->get('id'), 'parent_id' => $parentId], 'api');
check('посторонний НЕ создаёт подзадачу', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['type' => 'bugfix', 'title' => 'SMOKE sub', 'deadline' => $DEADLINE, 'fields' => $BUG, 'assignee_id' => $collab->get('id'), 'parent_id' => $parentId], 'api');
check('автор создал подзадачу (исполнитель — соисполнитель)', $res['success'], (string) $res['message']);
$subId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $subId;

$service->move($author, $parentId, 'in_progress', '', 'api');
$service->move($author, $parentId, 'review', '', 'api');
$res = $service->move($author, $parentId, 'done', '', 'api');
check('родителя НЕЛЬЗЯ закрыть при открытой подзадаче', !$res['success'], (string) $res['message']);

$parent = $modx->getObject(MxBoardTask::class, $parentId);
check('соисполнитель подзадачи видит родителя (canView)', Visibility::canView($modx, $collab, $parent));
check('посторонний НЕ видит родителя', !Visibility::canView($modx, $stranger, $parent));

$cond = Visibility::boardCondition($modx, $collab, $project);
$q = $modx->newQuery(MxBoardTask::class);
$q->where(['project_id' => $projectId]);
if (!empty($cond)) {
    $q->where($cond);
}
$boardIds = [];
foreach ($modx->getCollection(MxBoardTask::class, $q) as $t) {
    $boardIds[] = (int) $t->get('id');
}
check('в канбане соисполнителя есть его подзадача', in_array($subId, $boardIds, true));
check('в канбане соисполнителя НЕТ родителя (не шумит)', !in_array($parentId, $boardIds, true));

driveToDone($service, $author, $subId);
check('подзадача закрыта', (int) $modx->getObject(MxBoardTask::class, $subId)->get('closedon') > 0);
$res = $service->move($author, $parentId, 'done', '', 'api');
check('после закрытия подзадачи родителя МОЖНО закрыть', $res['success'], (string) $res['message']);

/* --- 7. Журнал ------------------------------------------------------------- */

check('оспаривание в журнале', $modx->getCount(MxBoardLog::class, ['task_id' => $dlId, 'action' => 'deadline_dispute']) >= 1);
check('subtask_add в журнале родителя', $modx->getCount(MxBoardLog::class, ['task_id' => $parentId, 'action' => 'subtask_add']) >= 1);

/* --- Уборка ---------------------------------------------------------------- */

foreach ($createdTaskIds as $id) {
    if ($id && ($t = $modx->getObject(MxBoardTask::class, $id))) {
        $t->remove();
    }
}
foreach (['mxb_test_author', 'mxb_test_worker', 'mxb_test_collab', 'mxb_test_stranger'] as $u) {
    if ($obj = $modx->getObject(modUser::class, ['username' => $u])) {
        foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $obj->get('id')]) as $m) {
            $m->remove();
        }
        $obj->remove();
    }
}

echo "\n== Итог: {$pass} прошло, {$fail} упало ==\n";
exit($fail > 0 ? 1 : 0);
