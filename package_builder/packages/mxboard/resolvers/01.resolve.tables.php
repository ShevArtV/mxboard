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
];

$manager = $modx->getManager();

// Миграции: ALTER TABLE ДО createObjectContainer (иначе xPDO падает на новом поле).
// Ключ — FQCN модели: имя таблицы из mxboard_task_type в класс через str_replace+ucfirst
// не разворачивается (дал бы MxBoardTask_type), поэтому маппим напрямую.
$migrations = [
    \MxBoard\Model\MxBoardColumn::class => [
        'ADD COLUMN `color` VARCHAR(7) NOT NULL DEFAULT \'#6c757d\' AFTER `stage_key`',
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
            if (str_contains($e->getMessage(), 'Duplicate column')) {
                continue;
            }
            $modx->log(modX::LOG_LEVEL_WARN, "[mxBoard] Миграция {$fullTable}: " . $e->getMessage());
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

$modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Таблицы проверены/созданы.');

return true;
