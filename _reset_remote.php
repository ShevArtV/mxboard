<?php

/**
 * Сброс таблиц mxBoard на стенде: DROP всех таблиц пакета.
 *
 * Нужен ОДИН раз при переходе на v2 — схема сменилась целиком, миграций нет, данные
 * на стенде выкидные. createObjectContainer при установке не ALTER'ит существующие
 * таблицы, поэтому старые (v1) надо снести, иначе сид v2 упадёт на отсутствующих колонках.
 *
 * Запуск на стенде (рядом с config.core.php):
 *   /usr/local/php/php-8.3/bin/php _reset_remote.php
 *
 * НЕ класть в прод: удаляет данные доски безвозвратно.
 */

use MODX\Revolution\modX;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbreset');
$modx->initialize('mgr');

$prefix = (string) $modx->getOption('table_prefix');

// Включая старую v1-таблицу mxboard_board — её в новой схеме нет.
$tables = [
    'mxboard_board',
    'mxboard_department',
    'mxboard_project',
    'mxboard_column',
    'mxboard_task_type',
    'mxboard_field',
    'mxboard_task',
    'mxboard_comment',
    'mxboard_log',
    'mxboard_token',
];

foreach ($tables as $t) {
    $name = $prefix . $t;
    $modx->exec("DROP TABLE IF EXISTS `{$name}`");
    echo "dropped {$name}\n";
}

echo "RESET DONE\n";
