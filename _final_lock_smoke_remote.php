<?php

/**
 * Smoke read-only закрытой карточки (in-process, как остальные смоуки проекта).
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _final_lock_smoke_remote.php
 *
 * Что проверяем (карточка #2607-103):
 *   — в финальной стадии запрещены правка полей, комментарии (создать/править/удалить),
 *     вложения (залить/удалить), споры и их разрешение, удаление карточки;
 *   — отказ приходит одним лексиконным сообщением mxboard_err_task_closed;
 *   — отказ не применяется частично: заголовок и число комментариев не меняются;
 *   — смена стадии из финальной колонки НАЗАД по-прежнему работает (иначе карточку
 *     было бы не разблокировать);
 *   — после возврата из финала правки и комментарии снова проходят;
 *   — запрет держится по признаку `is_final`, а не по ключу колонки: финальная стадия
 *     проекта смоука называется `closed`, не `done`.
 *
 * Тестовые данные создаются в отдельном проекте `fsmoke` и убираются за собой.
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardAttachment;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
use MxBoard\Service\AttachmentService;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbfinalsmoke');
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

/** Текст отказа закрытой карточки — с ним сверяем каждое сообщение. */
$CLOSED = (string) $modx->lexicon('mxboard_err_task_closed');

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

/** Операция обязана быть отклонена ИМЕННО как «карточка закрыта», а не по другой причине. */
function checkClosed(string $name, array $result): void
{
    global $CLOSED;
    [$ok, , $msg] = $result;
    check($name, !$ok && $msg === $CLOSED, $ok ? 'операция прошла' : 'другая причина: ' . $msg);
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

echo "== mxBoard: smoke read-only закрытой карточки ==\n";

/** @var MxBoardProject|null $base */
$base = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
if (!$base) {
    fwrite(STDERR, "нет проекта default — пакет не установлен?\n");
    exit(1);
}
$department = $modx->getObject(MxBoardDepartment::class, (int) $base->get('department_id'));
$departmentId = (int) $department->get('id');
$usergroupId = (int) $department->get('usergroup_id');

// Порог «супер группы отдела»: роль authority=1 считается менеджерской. Настройку правим
// и в базе — getOption читает системный кэш, одного setOption мало.
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

$author = ensureUser($modx, 'mxb_f_author');
$worker = ensureUser($modx, 'mxb_f_worker');
$mgr = ensureUser($modx, 'mxb_f_mgr');
ensureMember($modx, $usergroupId, (int) $author->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $worker->get('id'), 0);
ensureMember($modx, $usergroupId, (int) $mgr->get('id'), $roleId);

$authorId = (int) $author->get('id');
$workerId = (int) $worker->get('id');

// Финальная колонка называется `closed`, а не `done`: запрет обязан держаться на флаге
// is_final, иначе проект с другим неймингом стадий остался бы без защиты.
[$ok, $obj, $msg] = run($modx, $mgr, $P . 'Project\\Create', [
    'department_id' => $departmentId,
    'key' => 'fsmoke',
    'name' => 'Закрытая карточка: смоук',
    'columns' => json_encode([
        ['key' => 'backlog', 'name' => 'Бэклог', 'is_initial' => 1, 'is_final' => 0, 'move_roles' => 'author,assignee'],
        ['key' => 'work', 'name' => 'В работе', 'is_initial' => 0, 'is_final' => 0, 'is_start' => 1, 'move_roles' => 'author,assignee'],
        ['key' => 'closed', 'name' => 'Закрыта', 'is_initial' => 0, 'is_final' => 1, 'move_roles' => 'author'],
    ], JSON_UNESCAPED_UNICODE),
]);
$projectId = (int) ($obj['id'] ?? 0);
check('проект fsmoke создан', $ok && $projectId > 0, $msg);
if ($projectId <= 0) {
    fwrite(STDERR, "не удалось создать проект — дальше смысла нет\n");
    exit(1);
}

[$ok, , $msg] = run($modx, $mgr, $P . 'Type\\Create', [
    'department_id' => $departmentId,
    'key' => 'fsmoke_type',
    'name' => 'Закрытая карточка: тип',
    'fields' => json_encode([['key' => 'what', 'label' => 'Что', 'type' => 'text', 'required' => 1]], JSON_UNESCAPED_UNICODE),
]);
check('тип fsmoke_type создан', $ok, $msg);

[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', [
    'project' => 'fsmoke', 'type' => 'fsmoke_type', 'title' => 'Карточка смоука',
    'deadline' => time() + 7 * 86400, 'plan_hours' => 4, 'assignee_id' => $workerId,
    'fields' => ['what' => 'смоук закрытия'],
]);
$taskId = (int) ($obj['id'] ?? 0);
check('карточка создана', $ok && $taskId > 0, $msg);
if ($taskId <= 0) {
    fwrite(STDERR, "не удалось создать карточку — дальше смысла нет\n");
    exit(1);
}

// Комментарий и вложение заводим ДО закрытия — иначе править/удалять будет нечего.
[$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Comment', ['id' => $taskId, 'content' => 'до закрытия']);
$commentId = (int) ($obj['id'] ?? 0);
check('комментарий до закрытия добавлен', $ok && $commentId > 0, $msg);

/** @var MxBoardAttachment $attachment */
$attachment = $modx->newObject(MxBoardAttachment::class);
$attachment->fromArray([
    'task_id' => $taskId,
    'comment_id' => 0,
    'user_id' => $authorId,
    'name' => 'fsmoke.txt',
    // Пути к физфайлу нет сознательно: проверяем guard, а не работу с media source.
    'path' => '',
    'url' => '',
    'size' => 1,
    'ext' => 'txt',
    'mime' => 'text/plain',
    'createdon' => time(),
]);
$attachment->save();
$attachmentId = (int) $attachment->get('id');
check('вложение до закрытия заведено', $attachmentId > 0);

$attachments = new AttachmentService($modx);

// Пустой список файлов до закрытия обязан упираться в «нет файла», а не в guard —
// иначе следующая проверка ничего бы не доказывала.
$modx->user = $author;
$openUpload = $attachments->upload($author, $taskId, 0, []);
check('до закрытия upload упирается в отсутствие файла, не в guard',
    !$openUpload['success'] && $openUpload['message'] === (string) $modx->lexicon('mxboard_err_upload_no_file'),
    $openUpload['message']);

// --- Закрываем карточку -------------------------------------------------------
echo "== закрытие карточки ==\n";

[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'work']);
check('перевод в work', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'closed']);
check('перевод в финальную closed', $ok, $msg);

/** @var MxBoardTask|null $closedTask */
$closedTask = $modx->getObject(MxBoardTask::class, $taskId);
check('карточка отмечена закрытой (closedon)', $closedTask !== null && (int) $closedTask->get('closedon') > 0);

// --- Запреты ------------------------------------------------------------------
echo "== запрещённые операции на закрытой карточке ==\n";

checkClosed('Task/Update (заголовок) отклонён',
    run($modx, $author, $P . 'Task\\Update', ['id' => $taskId, 'title' => 'ПОПЫТКА ПРАВКИ']));
checkClosed('Task/Update (поля типа) отклонён',
    run($modx, $author, $P . 'Task\\Update', ['id' => $taskId, 'fields' => json_encode(['what' => 'подмена'])]));
checkClosed('Task/Update менеджером отклонён',
    run($modx, $mgr, $P . 'Task\\Update', ['id' => $taskId, 'title' => 'ПОПЫТКА МЕНЕДЖЕРА']));
checkClosed('Task/Comment отклонён',
    run($modx, $author, $P . 'Task\\Comment', ['id' => $taskId, 'content' => 'после закрытия']));
checkClosed('Task/CommentUpdate отклонён',
    run($modx, $author, $P . 'Task\\CommentUpdate', ['comment_id' => $commentId, 'content' => 'правка после закрытия']));
checkClosed('Task/CommentDelete отклонён',
    run($modx, $author, $P . 'Task\\CommentDelete', ['comment_id' => $commentId]));
checkClosed('Task/DisputeDeadline отклонён',
    run($modx, $worker, $P . 'Task\\DisputeDeadline', ['id' => $taskId, 'proposed_date' => time() + 14 * 86400, 'reason' => 'смоук']));
checkClosed('Task/DisputePlan отклонён',
    run($modx, $worker, $P . 'Task\\DisputePlan', ['id' => $taskId, 'proposed_hours' => 8, 'reason' => 'смоук']));
checkClosed('Task/ResolveDeadline отклонён',
    run($modx, $author, $P . 'Task\\ResolveDeadline', ['id' => $taskId, 'accept' => 1]));
checkClosed('Task/ResolvePlan отклонён',
    run($modx, $author, $P . 'Task\\ResolvePlan', ['id' => $taskId, 'accept' => 1]));
checkClosed('Task/Remove отклонён',
    run($modx, $author, $P . 'Task\\Remove', ['id' => $taskId]));

$modx->user = $author;
$closedUpload = $attachments->upload($author, $taskId, 0, []);
check('AttachmentService::upload отклонён как закрытая',
    !$closedUpload['success'] && $closedUpload['message'] === $CLOSED, $closedUpload['message']);

checkClosed('Task/AttachmentRemove отклонён',
    run($modx, $author, $P . 'Task\\AttachmentRemove', ['attachment_id' => $attachmentId]));

// --- Ничего не применилось частично -------------------------------------------
echo "== состояние после отказов ==\n";

/** @var MxBoardTask|null $after */
$after = $modx->getObject(MxBoardTask::class, $taskId);
check('заголовок не изменился', $after !== null && $after->get('title') === 'Карточка смоука',
    $after ? (string) $after->get('title') : 'карточки нет');
check('поля типа не изменились',
    $after !== null && (($after->get('fields')['what'] ?? '') === 'смоук закрытия'));
check('карточка не удалена', $after !== null);
check('комментарий один и не изменён',
    (int) $modx->getCount(MxBoardComment::class, ['task_id' => $taskId]) === 1
    && ($cm = $modx->getObject(MxBoardComment::class, $commentId)) !== null
    && $cm->get('content') === 'до закрытия');
check('вложение на месте', $modx->getObject(MxBoardAttachment::class, $attachmentId) !== null);
check('спор не поднят', $after !== null
    && (int) $after->get('deadline_disputed') === 0 && (int) $after->get('plan_disputed') === 0);

// --- Смена стадии из финала разрешена -----------------------------------------
echo "== смена стадии из финальной колонки ==\n";

[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'work', 'note' => 'вернули из финала']);
check('Task/Move из финала назад разрешён', $ok, $msg);

/** @var MxBoardTask|null $reopened */
$reopened = $modx->getObject(MxBoardTask::class, $taskId);
check('отметка закрытия снята', $reopened !== null && (int) $reopened->get('closedon') === 0);

// --- После возврата правки снова работают -------------------------------------
echo "== после возврата из финала ==\n";

[$ok, , $msg] = run($modx, $author, $P . 'Task\\Update', ['id' => $taskId, 'title' => 'Карточка смоука (правка)']);
check('Task/Update снова проходит', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Comment', ['id' => $taskId, 'content' => 'после возврата']);
check('Task/Comment снова проходит', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\CommentDelete', ['comment_id' => $commentId]);
check('Task/CommentDelete снова проходит', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\AttachmentRemove', ['attachment_id' => $attachmentId]);
check('Task/AttachmentRemove снова проходит', $ok, $msg);

// Удаление закрытой карточки запрещено, открытой — нет: закрываем снова и убеждаемся,
// что порядок «вернуть из финала → удалить» действительно работает.
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'closed']);
check('карточка закрыта повторно', $ok, $msg);
checkClosed('Task/Remove на повторно закрытой отклонён',
    run($modx, $author, $P . 'Task\\Remove', ['id' => $taskId]));
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Move', ['id' => $taskId, 'column' => 'work']);
check('карточка снова открыта', $ok, $msg);
[$ok, , $msg] = run($modx, $author, $P . 'Task\\Remove', ['id' => $taskId]);
check('Task/Remove после возврата проходит', $ok, $msg);
check('карточка удалена', $modx->getObject(MxBoardTask::class, $taskId) === null);

// --- Уборка -------------------------------------------------------------------
echo "== teardown ==\n";

if ($task = $modx->getObject(MxBoardTask::class, $taskId)) {
    $task->remove();
}
foreach ($modx->getCollection(MxBoardAttachment::class, ['task_id' => $taskId]) as $a) {
    $a->remove();
}
foreach ($modx->getCollection(MxBoardComment::class, ['task_id' => $taskId]) as $cm) {
    $cm->remove();
}
if ($type = $modx->getObject(MxBoardTaskType::class, ['key' => 'fsmoke_type'])) {
    foreach ($modx->getCollection(MxBoardField::class, ['task_type_id' => $type->get('id')]) as $f) {
        $f->remove();
    }
    $type->remove();
}
if ($project = $modx->getObject(MxBoardProject::class, ['key' => 'fsmoke'])) {
    foreach ($modx->getCollection(MxBoardColumn::class, ['project_id' => $project->get('id')]) as $col) {
        $col->remove();
    }
    foreach ($modx->getCollection(MxBoardTask::class, ['project_id' => $project->get('id')]) as $t) {
        $t->remove();
    }
    $project->remove();
}
foreach (['mxb_f_author', 'mxb_f_worker', 'mxb_f_mgr'] as $username) {
    /** @var modUser|null $user */
    $user = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$user) {
        continue;
    }
    $uid = (int) $user->get('id');
    foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $uid]) as $m) {
        $m->remove();
    }
    foreach ($modx->getCollection(MxBoardTask::class, ['author_id' => $uid]) as $t) {
        $t->remove();
    }
    $user->remove();
}

echo "\nИтог: PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
