<?php

/**
 * Точечная проверка файловой подсистемы (подзадача A) на стенде.
 * Запуск: /usr/local/php/php-8.3/bin/php _attach_verify_remote.php
 *
 * Переиспользует сид smoke (проект default, тип bugfix, тестовые юзеры). Гоняет:
 * загрузку файла к задаче и к комменту, чтение через taskDetail, проверку прав,
 * каскад при удалении коммента (файл сообщения исчезает) и задачи (все файлы исчезают).
 * За собой чистит.
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserProfile;
use MODX\Revolution\modX;
use MODX\Revolution\Sources\modMediaSource;
use MxBoard\Model\MxBoardAttachment;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
use MxBoard\Service\AttachmentService;
use MxBoard\Service\BoardQuery;
use MxBoard\Service\TaskService;

define('MODX_API_MODE', true);
require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbattach');
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
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

// Абсолютный путь физфайла в источнике (file source, относительный basePath).
function absPath(modMediaSource $source, string $path): string
{
    $props = $source->getPropertyList();
    $base = (string) ($props['basePath'] ?? '');
    $relative = !empty($props['basePathRelative']);
    $absBase = $relative ? rtrim(MODX_BASE_PATH, '/') . '/' . ltrim($base, '/') : $base;

    return rtrim($absBase, '/') . '/' . ltrim($path, '/');
}

echo "== mxBoard: проверка файловой подсистемы (A) ==\n";

// 0. Таблица вложений.
$at = $modx->getTableName(MxBoardAttachment::class);
$acols = [];
foreach ($modx->query("SHOW COLUMNS FROM {$at}")->fetchAll(PDO::FETCH_COLUMN) as $c) { $acols[] = $c; }
foreach (['task_id', 'comment_id', 'user_id', 'name', 'path', 'url', 'size', 'ext', 'mime', 'createdon'] as $col) {
    check("колонка attachment.{$col}", in_array($col, $acols, true));
}

// 1. Media source.
$sourceId = (int) $modx->getOption('mxboard.media_source', null, 0);
check('mxboard.media_source задан (>0)', $sourceId > 0, "id={$sourceId}");
$source = modMediaSource::getDefaultSource($modx, $sourceId > 0 ? $sourceId : null);
check('media source резолвится', $source instanceof modMediaSource);
if ($source) { $source->initialize(); }

// 2. Сид.
$project = $modx->getObject(MxBoardProject::class, ['key' => 'default']);
if (!$project) { echo "нет проекта default — сначала запусти _smoke_remote.php\n"; exit(1); }
$bug = $modx->getObject(MxBoardTaskType::class, ['key' => 'bugfix']);
if (!$bug) { echo "нет типа bugfix\n"; exit(1); }
$department = $modx->getObject(MxBoardDepartment::class, (int) $project->get('department_id'));
$usergroupId = $department ? (int) $department->get('usergroup_id') : 0;

$ensureUser = function (string $username) use ($modx): modUser {
    $u = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$u) {
        $u = $modx->newObject(modUser::class);
        $u->set('username', $username);
        $p = $modx->newObject(modUserProfile::class);
        $p->set('email', $username . '@mxboard.test');
        $u->addOne($p);
        $u->set('active', 1);
        $u->save();
    }
    return $u;
};
$joinDept = function (modUser $u, int $gid) use ($modx): void {
    if ($gid > 0 && !$modx->getObject(modUserGroupMember::class, ['member' => $u->get('id'), 'user_group' => $gid])) {
        $m = $modx->newObject(modUserGroupMember::class);
        $m->fromArray(['user_group' => $gid, 'member' => (int) $u->get('id'), 'role' => 0, 'rank' => 0]);
        $m->save();
    }
};

$author = $ensureUser('mxb_test_author');
$worker = $ensureUser('mxb_test_worker');
$outsider = $ensureUser('mxb_test_outsider'); // НЕ в отделе — для проверки прав
$joinDept($author, $usergroupId);
$joinDept($worker, $usergroupId);

$service = new TaskService($modx);
$attachments = new AttachmentService($modx);
$query = new BoardQuery($modx);

// Временный PNG (1x1) как «загруженный» файл.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$mkTmp = function () use ($png): string {
    $tmp = tempnam(sys_get_temp_dir(), 'mxb');
    file_put_contents($tmp, $png);
    return $tmp;
};
$fileArg = function (string $tmp, string $name) : array {
    return [['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'image/png']];
};

// 3. Задача.
$create = $service->create($author, [
    'type' => 'bugfix',
    'title' => 'Проверка вложений',
    'tor' => 'Тест файловой подсистемы.',
    'deadline' => time() + 7 * 86400,
    'assignee_id' => (int) $worker->get('id'),
    'fields' => ['where' => 'x', 'what' => 'y', 'steps' => 'z', 'expected' => 'w'],
], 'api');
$taskId = (int) ($create['object']['id'] ?? 0);
check('задача создана', $taskId > 0, (string) $create['message']);
if ($taskId <= 0) { echo "\n== Итог: {$pass} OK, " . ($fail + 1) . " FAIL ==\n"; exit(1); }
$task = $modx->getObject(MxBoardTask::class, $taskId);

// 4. Вложение к задаче (comment_id=0).
$tmp1 = $mkTmp();
$up1 = $attachments->upload($author, $taskId, 0, $fileArg($tmp1, 'task-shot.png'));
@unlink($tmp1);
check('файл к задаче загружен', $up1['success'], (string) $up1['message']);
$taskAtt = $up1['object']['attachments'][0] ?? null;
check('вернулась запись вложения задачи', is_array($taskAtt) && ($taskAtt['id'] ?? 0) > 0);
check('is_image=true для png', is_array($taskAtt) && !empty($taskAtt['is_image']));
$taskAttPath = '';
if (is_array($taskAtt)) {
    $rec = $modx->getObject(MxBoardAttachment::class, (int) $taskAtt['id']);
    $taskAttPath = $rec ? (string) $rec->get('path') : '';
    check('физфайл задачи существует', $source && $taskAttPath !== '' && file_exists(absPath($source, $taskAttPath)), $taskAttPath);
}

// 5. Комментарий + вложение к нему.
$commentRes = $service->comment($worker, $taskId, 'Прикладываю скрин', 'mgr');
$commentId = (int) ($commentRes['object']['id'] ?? 0);
check('комментарий создан', $commentId > 0, (string) $commentRes['message']);
$tmp2 = $mkTmp();
$up2 = $attachments->upload($worker, $taskId, $commentId, $fileArg($tmp2, 'comment-shot.png'));
@unlink($tmp2);
check('файл к комменту загружен', $up2['success'], (string) $up2['message']);
$commentAtt = $up2['object']['attachments'][0] ?? null;
$commentAttPath = '';
if (is_array($commentAtt)) {
    $rec = $modx->getObject(MxBoardAttachment::class, (int) $commentAtt['id']);
    $commentAttPath = $rec ? (string) $rec->get('path') : '';
    check('физфайл коммента существует', $source && $commentAttPath !== '' && file_exists(absPath($source, $commentAttPath)), $commentAttPath);
}

// 6. Чтение через taskDetail.
$detail = $query->taskDetail($author, $task);
check('taskDetail.attachments = 1 (уровня задачи)', is_array($detail) && count($detail['attachments'] ?? []) === 1);
$firstComment = $detail['comments'][0] ?? null;
check('taskDetail: у коммента 1 вложение', is_array($firstComment) && count($firstComment['attachments'] ?? []) === 1);

// 7. Права: посторонний не удаляет вложение коммента.
if (is_array($commentAtt)) {
    $denied = $attachments->delete($outsider, (int) $commentAtt['id']);
    check('посторонний НЕ удаляет вложение', !$denied['success'], (string) $denied['message']);
    check('вложение коммента на месте', $modx->getObject(MxBoardAttachment::class, (int) $commentAtt['id']) !== null);
}

// 8. Каскад: удаление коммента сносит его вложение (запись + физфайл).
$delComment = $service->deleteComment($worker, $commentId, 'mgr');
check('коммент удалён', $delComment['success'], (string) $delComment['message']);
check('запись вложения коммента исчезла', $modx->getCount(MxBoardAttachment::class, ['comment_id' => $commentId]) === 0);
if ($source && $commentAttPath !== '') {
    check('физфайл коммента удалён', !file_exists(absPath($source, $commentAttPath)), $commentAttPath);
}

// 9. Каскад: удаление задачи сносит все вложения (запись + физфайл).
$delTask = $service->delete($author, $taskId, 'mgr');
check('задача удалена', $delTask['success'], (string) $delTask['message']);
check('записей вложений задачи не осталось', $modx->getCount(MxBoardAttachment::class, ['task_id' => $taskId]) === 0);
if ($source && $taskAttPath !== '') {
    check('физфайл задачи удалён', !file_exists(absPath($source, $taskAttPath)), $taskAttPath);
}

echo "\n== Итог: {$pass} OK, {$fail} FAIL ==\n";
exit($fail > 0 ? 1 : 0);
