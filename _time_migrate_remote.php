<?php

/**
 * Миграция стенда под учёт времени (план + факт), пока пакет не собран transport'ом.
 *
 * Повторяет то, что при установке сделал бы резолвер 01.resolve.tables.php: добавляет
 * поля `mxboard_column.is_start`, `mxboard_task.plan_hours|plan_disputed|plan_proposed`.
 * Затем помечает стартовую стадию — но ТОЛЬКО там, где носителя ещё нет: набор стадий
 * настраивают руками, и затирать эту настройку нельзя (правило 2.7.0).
 *
 * Идемпотентен: повторный запуск ничего не ломает.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _time_migrate_remote.php [ключ_стартовой_стадии]
 */

use MODX\Revolution\modX;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbtime');
$modx->initialize('mgr');

$startKey = $argv[1] ?? 'to_start';
$prefix = (string) $modx->getOption('table_prefix');
$columns = $prefix . 'mxboard_column';
$tasks = $prefix . 'mxboard_task';

$migrations = [
    $columns => [
        'ADD COLUMN `is_start` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_final`',
    ],
    $tasks => [
        'ADD COLUMN `plan_hours` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `deadline_proposed`',
        'ADD COLUMN `plan_disputed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `plan_hours`',
        'ADD COLUMN `plan_proposed` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `plan_disputed`',
    ],
];

foreach ($migrations as $table => $sqls) {
    foreach ($sqls as $sql) {
        try {
            $modx->exec("ALTER TABLE {$table} {$sql}");
            echo "OK   {$table}: {$sql}\n";
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'Duplicate column') || str_contains($msg, 'Duplicate key name')) {
                echo "SKIP {$table}: уже есть\n";
                continue;
            }
            echo "FAIL {$table}: {$msg}\n";
        }
    }
}

// Стартовая стадия: проставляем по проектам, где её ещё нет. project_id = 0 — глобальный
// шаблон колонок, он тоже отдельный «проект» в смысле инварианта.
$stmt = $modx->query("SELECT DISTINCT project_id FROM {$columns}");
$projectIds = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];

foreach ($projectIds as $projectId) {
    $projectId = (int) $projectId;

    $has = $modx->prepare("SELECT COUNT(*) FROM {$columns} WHERE project_id = ? AND is_start = 1");
    $has->execute([$projectId]);
    if ((int) $has->fetchColumn() > 0) {
        echo "SKIP проект #{$projectId}: стартовая стадия уже назначена\n";
        continue;
    }

    // Начальная стадия стартовой быть не может — отсчёт пошёл бы с момента постановки.
    $upd = $modx->prepare(
        "UPDATE {$columns} SET is_start = 1 WHERE project_id = ? AND `key` = ? AND is_initial = 0"
    );
    $upd->execute([$projectId, $startKey]);
    $done = $upd->rowCount();

    echo $done > 0
        ? "OK   проект #{$projectId}: стартовая стадия = {$startKey}\n"
        : "WARN проект #{$projectId}: стадии `{$startKey}` нет — отметьте стартовую вручную\n";
}

// Контроль: что в итоге лежит в стадиях.
$check = $modx->query("SELECT project_id, `key`, position, is_initial, is_start, is_final FROM {$columns} ORDER BY project_id, position");
echo "\nproject_id | key | pos | initial | start | final\n";
foreach (($check ? $check->fetchAll(\PDO::FETCH_ASSOC) : []) as $row) {
    printf(
        "%10d | %-12s | %3d | %7d | %5d | %5d\n",
        $row['project_id'],
        $row['key'],
        $row['position'],
        $row['is_initial'],
        $row['is_start'],
        $row['is_final']
    );
}

echo "\nГотово.\n";
