<?php

/**
 * Миграция стенда под справочник приоритетов, пока пакет не собран transport'ом.
 *
 * Повторяет то, что при установке сделает резолвер 01.resolve.tables.php: создаёт
 * таблицу `mxboard_priority` (уникальные индексы по value и name) и засеивает текущие
 * четыре значения (0 Низкий, 1 Обычный, 2 Высокий, 3 Критический) с цветами,
 * эквивалентными прежним severity во фронте — чтобы существующие карточки не осиротели.
 *
 * Идемпотентен: повторный запуск ничего не ломает. Сидинг — INSERT IGNORE по
 * уникальному value: если приоритет уже правили руками, значение не затирается.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _priority_migrate_remote.php
 */

use MODX\Revolution\modX;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbpriority');
$modx->initialize('mgr');

$prefix = (string) $modx->getOption('table_prefix');
$table = $prefix . 'mxboard_priority';

// utf8mb4 явно: дефолтный charset сервера может быть latin1, тогда кириллица в
// названии приоритета упала бы с Error 1366.
try {
    $modx->exec(
        "CREATE TABLE IF NOT EXISTS {$table} (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL DEFAULT '',
            `color` VARCHAR(7) NOT NULL DEFAULT '#6c757d',
            `value` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `createdon` INT(20) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `value` (`value`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "OK   {$table}: создана/на месте\n";
} catch (\Throwable $e) {
    echo "FAIL {$table}: " . $e->getMessage() . "\n";
}

// Цвета эквивалентны прежним severity бейджа: secondary/info/warn/danger.
$seed = [
    ['value' => 0, 'name' => 'Низкий',      'color' => '#64748b'],
    ['value' => 1, 'name' => 'Обычный',     'color' => '#3b82f6'],
    ['value' => 2, 'name' => 'Высокий',     'color' => '#f59e0b'],
    ['value' => 3, 'name' => 'Критический', 'color' => '#ef4444'],
];

$now = time();
$stmt = $modx->prepare("INSERT IGNORE INTO {$table} (`name`, `color`, `value`, `createdon`) VALUES (?, ?, ?, ?)");
foreach ($seed as $row) {
    try {
        $stmt->execute([$row['name'], $row['color'], $row['value'], $now]);
        echo "OK   seed {$row['value']} {$row['name']} ({$stmt->rowCount()} added)\n";
    } catch (\Throwable $e) {
        echo "FAIL seed {$row['value']}: " . $e->getMessage() . "\n";
    }
}

$check = $modx->query("SELECT id, value, name, color FROM {$table} ORDER BY value ASC");
echo "\nПриоритеты в базе:\n";
foreach (($check ? $check->fetchAll(\PDO::FETCH_ASSOC) : []) as $r) {
    echo "  #{$r['id']} value={$r['value']} {$r['name']} {$r['color']}\n";
}

echo "\nГотово.\n";
