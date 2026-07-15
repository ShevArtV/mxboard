<?php

/**
 * Smoke-тест mxBoard v2 на стенде: проверяет ровно те обещания, которые легко нарушить.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _smoke_remote.php
 *
 * Что проверяем:
 *   1. Сид: отдел, типы (bugfix/feature) с полями, проект default с 5 колонками (инварианты).
 *   2. Валидация create: без типа / без дедлайна / без обязательного поля — отказ; валидно — ок.
 *   3. Захват атомарен; исполнитель не закрывает, автор закрывает; запрет самоаттестации.
 *   4. Оспаривание дедлайна: reject не меняет дату, accept меняет.
 *   5. Подзадача блокирует финальную; закрытие подзадачи разблокирует.
 *   6. Видимость: посторонний не видит; соисполнитель подзадачи видит родителя (canView),
 *      но родитель НЕ попадает в его канбан (boardCondition).
 *   7. Журнал переходов пишется.
 *
 * Тестовые данные за собой убирает.
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Helpers\Visibility;
use MxBoard\Model\MxBoardColumn;
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

/** Прогнать карточку до финала руками автора (для закрытия подзадач в тесте). */
function driveToDone(TaskService $service, modUser $author, int $taskId): array
{
    $service->move($author, $taskId, 'ready', '', 'api');
    $service->move($author, $taskId, 'in_progress', '', 'api');
    $service->move($author, $taskId, 'review', '', 'api');

    return $service->move($author, $taskId, 'done', '', 'api');
}

$DEADLINE = time() + 7 * 86400;
$BUG_FIELDS = ['where' => 'checkout', 'what' => 'падает', 'steps' => 'открыть корзину', 'expected' => 'не падает'];
$FEAT_FIELDS = ['goal' => 'ускорить', 'criteria' => 'p95 < 200ms'];

echo "== mxBoard v2 smoke ==\n";

$service = new TaskService($modx);

/* --- 1. Сид ---------------------------------------------------------------- */

$project = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
check('проект default существует', (bool) $project);
if (!$project) {
    exit(1);
}
$projectId = (int) $project->get('id');

$columns = $modx->getCollection(MxBoardColumn::class, ['project_id' => $projectId]);
check('у проекта 5 колонок', count($columns) === 5, 'найдено: ' . count($columns));

$initialCount = $modx->getCount(MxBoardColumn::class, ['project_id' => $projectId, 'is_initial' => true]);
$finalCount = $modx->getCount(MxBoardColumn::class, ['project_id' => $projectId, 'is_final' => true]);
check('инвариант: ровно одна initial', $initialCount === 1, "initial: {$initialCount}");
check('инвариант: ровно одна final', $finalCount === 1, "final: {$finalCount}");

$bugType = $modx->getObject(MxBoardTaskType::class, ['key' => 'bugfix']);
$featType = $modx->getObject(MxBoardTaskType::class, ['key' => 'feature']);
check('тип bugfix есть', (bool) $bugType);
check('тип feature есть', (bool) $featType);
$bugFields = $bugType ? $modx->getCount(MxBoardField::class, ['task_type_id' => $bugType->get('id')]) : 0;
check('у bugfix ≥1 поле (рабочий тип)', $bugFields >= 1, "полей: {$bugFields}");

$author = ensureUser($modx, 'mxb_test_author');
$worker = ensureUser($modx, 'mxb_test_worker');
$worker2 = ensureUser($modx, 'mxb_test_worker2');
$collab = ensureUser($modx, 'mxb_test_collab');
$stranger = ensureUser($modx, 'mxb_test_stranger');

/* --- 2. Валидация create --------------------------------------------------- */

$res = $service->create($author, ['title' => 'нет типа', 'deadline' => $DEADLINE], 'api');
check('create без типа отклонён', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['title' => 'нет дедлайна', 'type' => 'bugfix', 'fields' => $BUG_FIELDS], 'api');
check('create без дедлайна отклонён', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['title' => 'нет обяз. поля', 'type' => 'bugfix', 'deadline' => $DEADLINE, 'fields' => ['where' => 'x']], 'api');
check('create без обязательного поля отклонён', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['title' => 'SMOKE bug', 'type' => 'bugfix', 'deadline' => $DEADLINE, 'fields' => $BUG_FIELDS], 'api');
check('create валидной задачи прошёл', $res['success'], (string) $res['message']);
$taskId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $taskId;
if ($taskId === 0) {
    exit(1);
}

/* --- 3. Захват / закрытие / самоаттестация --------------------------------- */

$service->move($author, $taskId, 'ready', '', 'api');
$r1 = $service->take($worker, $taskId, 'mcp');
$r2 = $service->take($worker2, $taskId, 'mcp');
$winners = (int) $r1['success'] + (int) $r2['success'];
check('захват атомарен: выиграл ровно один', $winners === 1, "успехов: {$winners}");

$task = $modx->getObject(MxBoardTask::class, $taskId);
$assignee = (int) $task->get('assignee_id');
$holder = $assignee === (int) $worker->get('id') ? $worker : $worker2;

$service->move($holder, $taskId, 'review', 'готово', 'mcp');
$res = $service->move($holder, $taskId, 'done', 'я всё сделал', 'mcp');
check('исполнитель НЕ может закрыть задачу', !$res['success'], (string) $res['message']);

$res = $service->move($author, $taskId, 'done', 'принято', 'mgr');
check('автор закрыл задачу', $res['success'], (string) $res['message']);
$task = $modx->getObject(MxBoardTask::class, $taskId);
check('closedon проставлен', (int) $task->get('closedon') > 0);

// Самоаттестация.
$res = $service->create($author, ['title' => 'SMOKE self', 'type' => 'feature', 'deadline' => $DEADLINE, 'fields' => $FEAT_FIELDS], 'api');
$selfId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $selfId;
$service->move($author, $selfId, 'ready', '', 'api');
$service->take($author, $selfId, 'mcp');
$service->move($author, $selfId, 'review', '', 'mcp');
$res = $service->move($author, $selfId, 'done', '', 'mcp');
check('автор-исполнитель НЕ может закрыть сам себя', !$res['success'], (string) $res['message']);

/* --- 4. Оспаривание дедлайна ----------------------------------------------- */

$res = $service->create($author, ['title' => 'SMOKE deadline', 'type' => 'bugfix', 'deadline' => $DEADLINE, 'fields' => $BUG_FIELDS], 'api');
$dlId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $dlId;
$service->move($author, $dlId, 'ready', '', 'api');
$service->take($worker, $dlId, 'mcp');

$newDate = $DEADLINE + 3 * 86400;
$res = $service->disputeDeadline($worker, $dlId, $newDate, 'нужно больше времени', 'mcp');
check('исполнитель оспорил дедлайн', $res['success'], (string) $res['message']);
$dl = $modx->getObject(MxBoardTask::class, $dlId);
check('флаг оспаривания выставлен', (bool) $dl->get('deadline_disputed'));

$res = $service->resolveDeadline($worker, $dlId, true, 'mgr');
check('исполнитель НЕ может сам разрешить оспаривание', !$res['success'], (string) $res['message']);

$service->resolveDeadline($author, $dlId, false, 'mgr');
$dl = $modx->getObject(MxBoardTask::class, $dlId);
check('reject: дедлайн НЕ изменился', (int) $dl->get('deadlineon') === $DEADLINE);
check('reject: флаг сброшен', !(bool) $dl->get('deadline_disputed'));

$service->disputeDeadline($worker, $dlId, $newDate, 'ещё раз', 'mcp');
$service->resolveDeadline($author, $dlId, true, 'mgr');
$dl = $modx->getObject(MxBoardTask::class, $dlId);
check('accept: дедлайн стал предложенным', (int) $dl->get('deadlineon') === $newDate, (string) $dl->get('deadlineon'));

/* --- 5. Подзадача блокирует финальную -------------------------------------- */

$res = $service->create($author, ['title' => 'SMOKE parent', 'type' => 'feature', 'deadline' => $DEADLINE, 'fields' => $FEAT_FIELDS], 'api');
$parentId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $parentId;

// Подзадачу создаёт исполнитель? Здесь автор — он вправе. Проверим и запрет для постороннего.
$res = $service->create($stranger, ['title' => 'SMOKE sub denied', 'type' => 'bugfix', 'deadline' => $DEADLINE, 'fields' => $BUG_FIELDS, 'parent_id' => $parentId], 'api');
check('посторонний НЕ может создать подзадачу', !$res['success'], (string) $res['message']);

$res = $service->create($author, ['title' => 'SMOKE sub', 'type' => 'bugfix', 'deadline' => $DEADLINE, 'fields' => $BUG_FIELDS, 'parent_id' => $parentId], 'api');
check('автор создал подзадачу', $res['success'], (string) $res['message']);
$subId = (int) ($res['object']['id'] ?? 0);
$createdTaskIds[] = $subId;

// Пытаемся закрыть родителя при открытой подзадаче.
$service->move($author, $parentId, 'ready', '', 'api');
$service->move($author, $parentId, 'in_progress', '', 'api');
$service->move($author, $parentId, 'review', '', 'api');
$res = $service->move($author, $parentId, 'done', '', 'api');
check('родителя НЕЛЬЗЯ закрыть при открытой подзадаче', !$res['success'], (string) $res['message']);

// Соисполнитель берёт подзадачу — проверим видимость до закрытия.
$service->move($author, $subId, 'ready', '', 'api');
$service->take($collab, $subId, 'mcp');

$parent = $modx->getObject(MxBoardTask::class, $parentId);
check('соисполнитель подзадачи видит родителя (canView)', Visibility::canView($modx, $collab, $parent));
check('посторонний НЕ видит родителя', !Visibility::canView($modx, $stranger, $parent));

// Канбан соисполнителя: подзадача — да, родитель — нет.
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
check('в канбане соисполнителя есть подзадача', in_array($subId, $boardIds, true));
check('в канбане соисполнителя НЕТ родителя', !in_array($parentId, $boardIds, true));

// Закрываем подзадачу — родитель разблокируется.
driveToDone($service, $author, $subId);
$sub = $modx->getObject(MxBoardTask::class, $subId);
check('подзадача закрыта', (int) $sub->get('closedon') > 0);

$res = $service->move($author, $parentId, 'done', '', 'api');
check('после закрытия подзадачи родителя МОЖНО закрыть', $res['success'], (string) $res['message']);

/* --- 6. Журнал ------------------------------------------------------------- */

$disputeLogs = $modx->getCount(MxBoardLog::class, ['task_id' => $dlId, 'action' => 'deadline_dispute']);
check('оспаривание записано в журнал', $disputeLogs >= 1, "записей: {$disputeLogs}");
$subLog = $modx->getCount(MxBoardLog::class, ['task_id' => $parentId, 'action' => 'subtask_add']);
check('создание подзадачи записано в журнал родителя', $subLog >= 1, "записей: {$subLog}");

/* --- Уборка ---------------------------------------------------------------- */

foreach ($createdTaskIds as $id) {
    if ($id && ($t = $modx->getObject(MxBoardTask::class, $id))) {
        $t->remove();
    }
}
foreach (['mxb_test_author', 'mxb_test_worker', 'mxb_test_worker2', 'mxb_test_collab', 'mxb_test_stranger'] as $u) {
    if ($obj = $modx->getObject(modUser::class, ['username' => $u])) {
        $obj->remove();
    }
}

echo "\n== Итог: {$pass} прошло, {$fail} упало ==\n";
exit($fail > 0 ? 1 : 0);
