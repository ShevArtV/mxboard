<?php

/**
 * Resolver: создание/обновление таблиц mxBoard при install/upgrade.
 *
 * Таблицы НЕ дропаются при uninstall — доска с историей задач переживает
 * переустановку пакета.
 *
 * Charset форсируется в utf8mb4: createObjectContainer создаёт таблицу в дефолтном
 * charset сервера (может быть latin1), и тогда вставка кириллицы падает с Error 1366.
 *
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */

use MODX\Revolution\modX;
use xPDO\Transport\xPDOTransport;

if (!$transport->xpdo) {
    return true;
}

/** @var modX $modx */
$modx = $transport->xpdo;
$action = $options[xPDOTransport::PACKAGE_ACTION] ?? '';

if ($action === xPDOTransport::ACTION_UNINSTALL) {
    return true;
}

$corePath = $modx->getOption('core_path') . 'components/mxboard/';

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$classes = [
    \MxBoard\Model\MxBoardDepartment::class,
    \MxBoard\Model\MxBoardProject::class,
    \MxBoard\Model\MxBoardColumn::class,
    \MxBoard\Model\MxBoardTaskType::class,
    \MxBoard\Model\MxBoardField::class,
    \MxBoard\Model\MxBoardTask::class,
    \MxBoard\Model\MxBoardComment::class,
    \MxBoard\Model\MxBoardLog::class,
    \MxBoard\Model\MxBoardToken::class,
    \MxBoard\Model\MxBoardAttachment::class,
    \MxBoard\Model\MxBoardCounter::class,
    \MxBoard\Model\MxBoardNotification::class,
];

$manager = $modx->getManager();

// Миграции: ALTER TABLE ДО createObjectContainer (иначе xPDO падает на новом поле).
// Ключ — FQCN модели: имя таблицы из mxboard_task_type в класс через str_replace+ucfirst
// не разворачивается (дал бы MxBoardTask_type), поэтому маппим напрямую.
$migrations = [
    \MxBoard\Model\MxBoardColumn::class => [
        'ADD COLUMN `description` TEXT NULL AFTER `name`',
        'ADD COLUMN `color` VARCHAR(7) NOT NULL DEFAULT \'#6c757d\' AFTER `move_roles`',
        // stage_key убран: тег стадии нигде не использовался (см. фичу «колонки v2»).
        'DROP COLUMN `stage_key`',
    ],
    \MxBoard\Model\MxBoardComment::class => [
        'ADD COLUMN `updatedon` INT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER `createdon`',
    ],
    \MxBoard\Model\MxBoardTaskType::class => [
        'ADD COLUMN `ai_check` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `active`',
        'ADD COLUMN `ai_prompt` TEXT NULL AFTER `ai_check`',
    ],
    \MxBoard\Model\MxBoardTask::class => [
        'ADD COLUMN `ai_verdict` MEDIUMTEXT NULL AFTER `meta`',
        // Человекочитаемый номер (num): nullable под unique (много NULL допустимо), бэкофилл ниже.
        'ADD COLUMN `num` VARCHAR(32) NULL DEFAULT NULL AFTER `column_id`',
        'ADD UNIQUE INDEX `num` (`num`)',
    ],
];

foreach ($migrations as $class => $sqls) {
    $fullTable = $modx->getTableName($class);
    if (!$fullTable) {
        continue;
    }
    foreach ($sqls as $sql) {
        try {
            $modx->exec("ALTER TABLE {$fullTable} {$sql}");
            $modx->log(modX::LOG_LEVEL_INFO, "[mxBoard] Миграция {$fullTable}: OK");
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Идемпотентность: колонка/индекс уже есть (ADD) или уже нет (DROP).
            if (str_contains($msg, 'Duplicate column')
                || str_contains($msg, 'Duplicate key name')
                || str_contains($msg, "Can't DROP")
                || str_contains($msg, 'check that column/key exists')) {
                continue;
            }
            $modx->log(modX::LOG_LEVEL_WARN, "[mxBoard] Миграция {$fullTable}: " . $msg);
        }
    }
}

foreach ($classes as $class) {
    $manager->createObjectContainer($class);

    // Enforce utf8mb4 — createObjectContainer мог создать таблицу в latin1.
    try {
        $table = $modx->getTableName($class);
        if (!$table) {
            continue;
        }
        $bare = trim($table, '`');
        $stmt = $modx->query('SHOW TABLE STATUS LIKE ' . $modx->quote($bare));
        $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : null;
        $collation = $row['Collation'] ?? '';
        if (stripos((string) $collation, 'utf8mb4') === false) {
            $modx->exec("ALTER TABLE {$table} CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $modx->log(modX::LOG_LEVEL_INFO, "[mxBoard] Таблица {$bare} приведена к utf8mb4.");
        }
    } catch (\Throwable $e) {
        $modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] Не удалось enforce utf8mb4: ' . $e->getMessage());
    }
}

// Бэкофилл num: проставить номера задачам без него (миграция на схему с num). Формат/период
// повторяют MxBoard\Helpers\TaskNum, но инлайном — резолвер не зависит от composer-autoload.
try {
    $format = trim((string) $modx->getOption('mxboard.task_num_format', null, '{y}{m}-{num}'));
    if ($format === '') {
        $format = '{y}{m}-{num}';
    }
    $periodFmt = str_contains($format, '{d}') ? 'Ymd'
        : (str_contains($format, '{m}') ? 'Ym'
        : ((str_contains($format, '{y}') || str_contains($format, '{Y}')) ? 'Y' : 'all'));

    $taskTable = $modx->getTableName(\MxBoard\Model\MxBoardTask::class);
    $counterTable = $modx->getTableName(\MxBoard\Model\MxBoardCounter::class);
    if ($taskTable && $counterTable) {
        $counters = [];
        $cs = $modx->query("SELECT period, value FROM {$counterTable}");
        foreach ($cs ? $cs->fetchAll(\PDO::FETCH_ASSOC) : [] as $r) {
            $counters[(string) $r['period']] = (int) $r['value'];
        }

        $rows = $modx->query("SELECT id, createdon FROM {$taskTable} WHERE num IS NULL OR num = '' ORDER BY createdon ASC, id ASC");
        $tasks = $rows ? $rows->fetchAll(\PDO::FETCH_ASSOC) : [];
        foreach ($tasks as $t) {
            $when = (int) $t['createdon'] ?: time();
            $period = $periodFmt === 'all' ? 'all' : date($periodFmt, $when);
            $seq = ($counters[$period] ?? 0) + 1;
            $counters[$period] = $seq;
            $num = strtr($format, [
                '{Y}' => date('Y', $when),
                '{y}' => date('y', $when),
                '{m}' => date('m', $when),
                '{d}' => date('d', $when),
                '{num}' => (string) $seq,
            ]);
            $upd = $modx->prepare("UPDATE {$taskTable} SET num = ? WHERE id = ?");
            $upd->execute([$num, (int) $t['id']]);
        }

        foreach ($counters as $period => $value) {
            $up = $modx->prepare("INSERT INTO {$counterTable} (period, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = GREATEST(value, VALUES(value))");
            $up->execute([$period, $value]);
        }
        if ($tasks) {
            $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Бэкофилл num: ' . count($tasks) . ' задач.');
        }
    }
} catch (\Throwable $e) {
    $modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] Бэкофилл num: ' . $e->getMessage());
}

$modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Таблицы проверены/созданы.');

return true;
