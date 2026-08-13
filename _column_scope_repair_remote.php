<?php

/**
 * Repair колонок задач под scope проекта (#2607-217).
 *
 * Находит карточки, чей raw `column_id` ссылается на колонку ЧУЖОГО scope (шаблон
 * project_id=0 при собственных колонках проекта или наоборот), и переставляет их на
 * одноимённую колонку своего проекта. Карточки, у которых такого ключа в проекте нет,
 * НЕ трогаются — они печатаются отдельным списком и требуют решения человека.
 *
 * По умолчанию dry-run: только отчёт. Запись — с флагом `--apply`.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _column_scope_repair_remote.php
 *   /usr/local/php/php-8.3/bin/php _column_scope_repair_remote.php --apply
 */

use MODX\Revolution\modX;
use MxBoard\Helpers\Columns;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbcolrepair');
$modx->initialize('mgr');

$apply = in_array('--apply', $argv ?? [], true);
echo $apply ? "Режим: ЗАПИСЬ (--apply)\n\n" : "Режим: dry-run (для записи добавьте --apply)\n\n";

/** @var array<int, string> $projectKey */
$projectKey = [];
/** @var array<int, int> $projectScope */
$projectScope = [];
foreach ($modx->getCollection(MxBoardProject::class) as $project) {
    $id = (int) $project->get('id');
    $projectKey[$id] = (string) $project->get('key');
    $projectScope[$id] = Columns::scope($modx, $id);
}

$fixable = [];
$unmatched = [];

/** @var MxBoardTask $task */
foreach ($modx->getCollection(MxBoardTask::class) as $task) {
    $projectId = (int) $task->get('project_id');
    if (!isset($projectScope[$projectId])) {
        continue;
    }

    $columnId = (int) $task->get('column_id');
    /** @var MxBoardColumn|null $column */
    $column = $modx->getObject(MxBoardColumn::class, $columnId);
    if (!$column || (int) $column->get('project_id') === $projectScope[$projectId]) {
        continue;
    }

    $effective = Columns::effectiveFor($modx, $task);
    $row = [
        'task' => $task,
        'num' => (string) $task->get('num'),
        'project' => $projectKey[$projectId],
        'from' => $columnId,
        'key' => (string) $column->get('key'),
        'to' => $effective ? (int) $effective->get('id') : 0,
        'closed' => (int) $task->get('closedon') > 0,
        'queue' => (int) $task->get('queue_id'),
    ];

    if ($row['to'] > 0 && $row['to'] !== $columnId) {
        $fixable[] = $row;
    } else {
        $unmatched[] = $row;
    }
}

echo "== Переносимые по ключу: " . count($fixable) . " ==\n";
$failed = 0;
foreach ($fixable as $row) {
    $status = 'план';
    if ($apply) {
        $status = Columns::normalize($modx, $row['task']) ? 'ok' : 'FAIL';
        if ($status === 'FAIL') {
            $failed++;
        }
    }
    printf(
        "%-5s #%-10s %-14s column_id %d -> %d (key=%s)%s%s\n",
        $status, $row['num'], $row['project'], $row['from'], $row['to'], $row['key'],
        $row['closed'] ? ' [закрыта]' : '',
        $row['queue'] ? ' [queue=' . $row['queue'] . ']' : ''
    );
}

echo "\n== Без одноимённой колонки в проекте (НЕ трогаем): " . count($unmatched) . " ==\n";
foreach ($unmatched as $row) {
    printf(
        "skip  #%-10s %-14s column_id %d (key=%s)%s\n",
        $row['num'], $row['project'], $row['from'], $row['key'], $row['closed'] ? ' [закрыта]' : ''
    );
}

echo "\nREPAIR_FIXABLE " . count($fixable) . "\n";
echo 'REPAIR_APPLIED ' . ($apply ? count($fixable) - $failed : 0) . "\n";
echo "REPAIR_FAILED {$failed}\n";
echo 'REPAIR_UNMATCHED ' . count($unmatched) . "\n";

$modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] column-scope repair: fixable=' . count($fixable)
    . ', applied=' . ($apply ? count($fixable) - $failed : 0) . ', unmatched=' . count($unmatched));
