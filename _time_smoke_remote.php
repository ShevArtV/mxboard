<?php

/**
 * Smoke учёта времени на стенде: план, оспаривание плана, отсчёт факта по стадиям.
 *
 * Прогоняет сервисный слой напрямую (как это делает любой канал) и за собой убирает —
 * созданная карточка удаляется в конце.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _time_smoke_remote.php
 */

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\TaskService;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbtimesmoke');
$modx->initialize('mgr');
$modx->getService('lexicon', 'modLexicon');
$modx->lexicon->load('mxboard:default');

$corePath = $modx->getOption('core_path') . 'components/mxboard/';
if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  OK   {$name}\n";
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
};

/** @var modUser|null $author */
$author = $modx->getObject(modUser::class, ['username' => $argv[1] ?? 'admin']);
if (!$author) {
    echo "Нет автора (первый аргумент — username, по умолчанию admin)\n";
    exit(1);
}

$service = new TaskService($modx);

// Исполнитель: член отдела проекта, кроме автора, и ОБЯЗАТЕЛЬНО не sudo — иначе он
// менеджер и вправе сам разрешить свой спор, а мы проверяем именно запрет.
$assigneeName = $argv[2] ?? '';
if ($assigneeName !== '') {
    /** @var modUser|null $assignee */
    $assignee = $modx->getObject(modUser::class, ['username' => $assigneeName]);
} else {
    $sql = "SELECT u.id FROM {$modx->getTableName(modUser::class)} u
            INNER JOIN {$modx->getOption('table_prefix')}member_groups mg ON mg.member = u.id
            INNER JOIN {$modx->getOption('table_prefix')}mxboard_department d ON d.usergroup_id = mg.user_group
            WHERE u.id != ? AND u.active = 1 AND u.sudo = 0 LIMIT 1";
    $stmt = $modx->prepare($sql);
    $stmt->execute([(int) $author->get('id')]);
    $assigneeId = (int) $stmt->fetchColumn();
    /** @var modUser|null $assignee */
    $assignee = $assigneeId > 0 ? $modx->getObject(modUser::class, $assigneeId) : null;
}
if (!$assignee) {
    echo "В отделе нет активного не-sudo пользователя, кроме автора — спор проверить не на ком\n";
    exit(1);
}
if ((bool) $assignee->get('sudo')) {
    echo "Исполнитель {$assignee->get('username')} — sudo: он менеджер, запрет на самостоятельное решение спора не проверить\n";
    exit(1);
}

echo "Автор: {$author->get('username')} · исполнитель: {$assignee->get('username')}\n\n";

// Тип задачи: первый рабочий тип отдела (у него есть поля) — берём его обязательные поля.
$typeSql = "SELECT t.id, t.key FROM {$modx->getOption('table_prefix')}mxboard_task_type t
            INNER JOIN {$modx->getOption('table_prefix')}mxboard_field f ON f.task_type_id = t.id
            WHERE t.active = 1 GROUP BY t.id LIMIT 1";
$type = $modx->query($typeSql)->fetch(\PDO::FETCH_ASSOC);
if (!$type) {
    echo "Нет рабочего типа задачи\n";
    exit(1);
}
$fieldsStmt = $modx->prepare("SELECT `key` FROM {$modx->getOption('table_prefix')}mxboard_field WHERE task_type_id = ? AND required = 1 AND type != 'files'");
$fieldsStmt->execute([(int) $type['id']]);
$fields = [];
foreach ($fieldsStmt->fetchAll(\PDO::FETCH_COLUMN) as $key) {
    $fields[$key] = 'smoke';
}

echo "1. Создание с планом\n";
$result = $service->create($author, [
    'type' => $type['key'],
    'title' => '[smoke] учёт времени',
    'deadline' => date('Y-m-d', time() + 86400 * 7),
    'assignee_id' => (int) $assignee->get('id'),
    'plan_hours' => '2.6',           // дробное → округление до 3
    'fields' => $fields,
], 'mgr');
if (!$result['success']) {
    echo "  FAIL создание: {$result['message']}\n";
    exit(1);
}
$taskId = (int) $result['object']['id'];
$reload = static fn (): MxBoardTask => $modx->getObject(MxBoardTask::class, $taskId);

$check('план округлён 2.6 → 3', (int) $result['object']['plan_hours'] === 3, 'plan_hours=' . $result['object']['plan_hours']);
$check('замер не начат в бэклоге', (int) $result['object']['startedon'] === 0);

echo "\n2. Отсчёт факта по стадиям\n";
$move = static function (modUser $user, string $column) use ($service, $taskId): array {
    return $service->move($user, $taskId, $column, '', 'mgr');
};

$r = $move($author, 'to_start');
$check('вход в стартовую стадию: замер пошёл', $r['success'] && (int) $r['object']['startedon'] > 0, $r['message']);
$startedAt = (int) $reload()->get('startedon');

$r = $move($assignee, 'plan');
$check('стадия правее стартовой: замер продолжается', $r['success'] && (int) $r['object']['startedon'] === $startedAt, $r['message']);

$r = $move($assignee, 'backlog');
$check('возврат в бэклог: замер сброшен', $r['success'] && (int) $r['object']['startedon'] === 0, $r['message']);

$r = $move($author, 'to_start');
$check('повторный старт: замер пошёл заново', $r['success'] && (int) $r['object']['startedon'] > 0, $r['message']);

echo "\n3. Оспаривание плана\n";
$r = $service->disputePlan($author, $taskId, 8, 'автор не вправе', 'mgr');
$check('автор оспорить не может', !$r['success'], $r['message']);

$r = $service->disputePlan($assignee, $taskId, 8, 'работы больше', 'mgr');
$check('исполнитель оспорил', $r['success'] && (int) $r['object']['plan_proposed'] === 8, $r['message']);

$r = $service->resolvePlan($assignee, $taskId, true, 'mgr');
$check('исполнитель сам решить не может', !$r['success'], $r['message']);

$r = $service->resolvePlan($author, $taskId, true, 'mgr');
$check('автор принял оценку 8ч', $r['success'] && (int) $r['object']['plan_hours'] === 8, $r['message']);
$check('флаг спора снят', !(bool) $reload()->get('plan_disputed'));

$r = $service->disputePlan($assignee, $taskId, 4, '', 'mgr');
$check('повторный спор открыт', $r['success']);
$r = $service->update($author, $taskId, ['plan_hours' => 5], 'mgr');
$check('правка плана автором закрывает спор', $r['success'] && (int) $r['object']['plan_hours'] === 5 && !(bool) $r['object']['plan_disputed'], $r['message']);

echo "\n4. Закрытие и факт\n";
$move($assignee, 'plan');
$move($author, 'in_progress');
$move($assignee, 'review');
$r = $move($author, 'done');
$task = $reload();
$check('карточка закрыта', $r['success'] && (int) $task->get('closedon') > 0, $r['message']);
$check('факт считается (closedon − startedon)', (int) $task->get('startedon') > 0
    && (int) $task->get('closedon') >= (int) $task->get('startedon'));
printf("       startedon=%d closedon=%d → факт %d сек\n",
    (int) $task->get('startedon'), (int) $task->get('closedon'),
    (int) $task->get('closedon') - (int) $task->get('startedon'));

echo "\n5. Оспаривание дедлайна (проверка существующего механизма)\n";
$r = $service->disputeDeadline($assignee, $taskId, strtotime('+14 day'), 'нужен перенос', 'mgr');
$check('исполнитель оспорил дедлайн', $r['success'] && (int) $r['object']['deadline_proposed'] > 0, $r['message']);
$r = $service->resolveDeadline($author, $taskId, true, 'mgr');
$check('автор принял новый дедлайн', $r['success'], $r['message']);
$after = $reload();
$check('дедлайн перенесён и спор снят',
    (int) $after->get('deadlineon') > time() + 86400 * 10 && !(bool) $after->get('deadline_disputed'),
    'deadlineon=' . date('Y-m-d', (int) $after->get('deadlineon')));

echo "\n6. Уборка\n";
$r = $service->delete($author, $taskId, 'mgr');
$check('тестовая карточка удалена', $r['success'], $r['message']);

echo "\nИтог: {$pass} OK, {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
