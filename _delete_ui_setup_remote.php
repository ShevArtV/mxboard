<?php

/**
 * Подготовка данных для ручной проверки удаления в manager-UI (карточка #2608-53).
 *
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _delete_ui_setup_remote.php
 *   --clean  — убрать за собой (проект duismoke, его карточки и тестового автора).
 *
 * Создаёт в отделе проекта mxboard проект `duismoke` и:
 *   A — родитель с подзадачей B (обе от пользователя claude): в UI удаляем B и смотрим,
 *       что в журнале A появилась запись «удалена подзадача»;
 *   C — карточка чужого автора с исполнителем claude, тут же удалённая автором:
 *       claude должен увидеть в колокольчике уведомление «Задача удалена».
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardNotification;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
use MxBoard\Service\TaskService;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbdeleteui');
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
$clean = in_array('--clean', $argv, true);

/** @return array{0: bool, 1: mixed, 2: string} */
function run(modX $modx, modUser $user, string $action, array $props = []): array
{
    $modx->user = $user;
    $response = $modx->runProcessor($action, $props);
    if (!$response) {
        return [false, null, 'runProcessor вернул false'];
    }
    $raw = $response->getResponse();
    $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($arr)) {
        return [false, null, 'нераспарсиваемый ответ'];
    }

    return [(bool) ($arr['success'] ?? false), $arr['object'] ?? null, (string) ($arr['message'] ?? '')];
}

/** @var modUser|null $claude */
$claude = $modx->getObject(modUser::class, ['username' => 'claude']);
if (!$claude) {
    fwrite(STDERR, "нет пользователя claude\n");
    exit(1);
}
$claudeId = (int) $claude->get('id');

if ($clean) {
    if ($project = $modx->getObject(MxBoardProject::class, ['key' => 'duismoke'])) {
        $pid = (int) $project->get('id');
        foreach ($modx->getCollection(MxBoardTask::class, ['project_id' => $pid]) as $task) {
            $task->remove();
        }
        foreach ($modx->getCollection(MxBoardColumn::class, ['project_id' => $pid]) as $col) {
            $col->remove();
        }
        $project->remove();
    }
    if ($type = $modx->getObject(MxBoardTaskType::class, ['key' => 'duismoke_type'])) {
        foreach ($modx->getCollection(MxBoardField::class, ['task_type_id' => $type->get('id')]) as $f) {
            $f->remove();
        }
        $type->remove();
    }
    if ($tmp = $modx->getObject(modUser::class, ['username' => 'mxb_ui_author'])) {
        $uid = (int) $tmp->get('id');
        foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $uid]) as $m) {
            $m->remove();
        }
        $tmp->remove();
    }
    foreach ($modx->getCollection(MxBoardNotification::class, ['user_id' => $claudeId, 'type' => 'delete']) as $n) {
        $n->remove();
    }
    echo "убрано\n";
    exit(0);
}

/** @var MxBoardProject|null $base */
$base = $modx->getObject(MxBoardProject::class, ['key' => 'mxboard']);
if (!$base) {
    fwrite(STDERR, "нет проекта mxboard\n");
    exit(1);
}
$departmentId = (int) $base->get('department_id');

[$ok, $obj, $msg] = run($modx, $claude, $P . 'Project\\Create', [
    'department_id' => $departmentId,
    'key' => 'duismoke',
    'name' => 'Удаление: проверка UI',
    'columns' => json_encode([
        ['key' => 'backlog', 'name' => 'Бэклог', 'is_initial' => 1, 'is_final' => 0, 'move_roles' => 'author,assignee'],
        ['key' => 'work', 'name' => 'В работе', 'is_initial' => 0, 'is_final' => 0, 'is_start' => 1, 'move_roles' => 'author,assignee'],
        ['key' => 'done', 'name' => 'Готово', 'is_initial' => 0, 'is_final' => 1, 'move_roles' => 'author'],
    ], JSON_UNESCAPED_UNICODE),
]);
echo 'проект duismoke: ' . ($ok ? 'ok' : 'FAIL ' . $msg) . "\n";

[$ok, , $msg] = run($modx, $claude, $P . 'Type\\Create', [
    'department_id' => $departmentId,
    'key' => 'duismoke_type',
    'name' => 'Удаление UI: тип',
    'fields' => json_encode([['key' => 'what', 'label' => 'Что', 'type' => 'text', 'required' => 1]], JSON_UNESCAPED_UNICODE),
]);
echo 'тип duismoke_type: ' . ($ok ? 'ok' : 'FAIL ' . $msg) . "\n";

// Тестовый автор в том же отделе — нужен, чтобы у claude появилось уведомление
// об удалении: актор себе уведомление не пишет.
/** @var modUser|null $tmpAuthor */
$tmpAuthor = $modx->getObject(modUser::class, ['username' => 'mxb_ui_author']);
if (!$tmpAuthor) {
    $tmpAuthor = $modx->newObject(modUser::class);
    $tmpAuthor->set('username', 'mxb_ui_author');
    $profile = $modx->newObject(modUserProfile::class);
    $profile->set('email', 'mxb_ui_author@mxboard.test');
    $tmpAuthor->addOne($profile);
    $tmpAuthor->set('active', 1);
    $tmpAuthor->save();
}
$department = $modx->getObject(\MxBoard\Model\MxBoardDepartment::class, $departmentId);
$usergroupId = (int) $department->get('usergroup_id');
/** @var modUserGroupMember|null $m */
$m = $modx->getObject(modUserGroupMember::class, ['user_group' => $usergroupId, 'member' => $tmpAuthor->get('id')]);
if (!$m) {
    $m = $modx->newObject(modUserGroupMember::class);
    $m->fromArray(['user_group' => $usergroupId, 'member' => (int) $tmpAuthor->get('id'), 'rank' => 0, 'role' => 0]);
    $m->save();
}

function makeTask(modX $modx, modUser $author, string $P, int $assigneeId, string $title, int $parentId = 0): int
{
    $props = [
        'project' => 'duismoke', 'type' => 'duismoke_type', 'title' => $title,
        'deadline' => time() + 7 * 86400, 'assignee_id' => $assigneeId,
        'fields' => ['what' => 'проверка удаления в UI'],
    ];
    if ($parentId > 0) {
        $props['parent_id'] = $parentId;
    }
    [$ok, $obj, $msg] = run($modx, $author, $P . 'Task\\Create', $props);
    if (!$ok) {
        echo '  FAIL создание «' . $title . '»: ' . $msg . "\n";
    }

    return $ok ? (int) ($obj['id'] ?? 0) : 0;
}

$parent = makeTask($modx, $claude, $P, $claudeId, 'UI: родитель для проверки удаления');
$sub = makeTask($modx, $claude, $P, $claudeId, 'UI: лишняя подзадача — удалить в интерфейсе', $parent);
echo "родитель id={$parent}, подзадача id={$sub}\n";

// Карточка чужого автора → удаляем ею же, чтобы claude получил уведомление.
$foreign = makeTask($modx, $tmpAuthor, $P, $claudeId, 'UI: карточка, удалённая другим пользователем');
$result = (new TaskService($modx))->delete($tmpAuthor, $foreign, 'mgr');
echo 'удаление чужой карточки: ' . ($result['success'] ? 'ok' : 'FAIL ' . $result['message']) . "\n";
echo 'уведомлений delete у claude: '
    . (int) $modx->getCount(MxBoardNotification::class, ['user_id' => $claudeId, 'type' => 'delete']) . "\n";
echo "готово\n";
