<?php

/**
 * Миграция стенда под очереди задач, пока пакет не собран transport'ом.
 *
 * Повторяет то, что при установке сделал бы резолвер 01.resolve.tables.php: создаёт
 * таблицу `mxboard_queue` и добавляет полям задачи `queue_id` / `queue_position`.
 *
 * Идемпотентен: повторный запуск ничего не ломает и ничего не затирает.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _queue_migrate_remote.php
 */

use MODX\Revolution\modX;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbqueue');
$modx->initialize('mgr');

$prefix = (string) $modx->getOption('table_prefix');
$queues = $prefix . 'mxboard_queue';
$tasks = $prefix . 'mxboard_task';

// Таблица очередей. CREATE ... IF NOT EXISTS + utf8mb4 явно: дефолтный charset сервера
// может быть latin1, и тогда кириллица в названии очереди упала бы с Error 1366.
try {
    $modx->exec(
        "CREATE TABLE IF NOT EXISTS {$queues} (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `project_id` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `key` VARCHAR(100) NOT NULL DEFAULT '',
            `name` VARCHAR(191) NOT NULL DEFAULT '',
            `description` TEXT NULL,
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `createdon` INT(20) UNSIGNED NOT NULL DEFAULT 0,
            `updatedon` INT(20) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `project_queue_key` (`project_id`, `key`),
            KEY `project_id` (`project_id`),
            KEY `active` (`active`),
            KEY `position` (`position`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "OK   {$queues}: создана/на месте\n";
} catch (\Throwable $e) {
    echo "FAIL {$queues}: " . $e->getMessage() . "\n";
}

$migrations = [
    $tasks => [
        'ADD COLUMN `queue_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `plan_proposed`',
        'ADD COLUMN `queue_position` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `queue_id`',
        'ADD INDEX `queue_id` (`queue_id`)',
        'ADD INDEX `queue_position` (`queue_position`)',
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

// Контроль: очереди на месте, поля у задач видны.
$check = $modx->query("SELECT COUNT(*) FROM {$queues}");
echo "\nОчередей в базе: " . ($check ? (int) $check->fetchColumn() : '?') . "\n";

$cols = $modx->query("SHOW COLUMNS FROM {$tasks} LIKE 'queue%'");
foreach (($cols ? $cols->fetchAll(\PDO::FETCH_ASSOC) : []) as $row) {
    echo "поле задачи: {$row['Field']} {$row['Type']} default={$row['Default']}\n";
}

echo "\nГотово.\n";
