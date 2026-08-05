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
    \MxBoard\Model\MxBoardQueue::class,
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
    \MxBoard\Model\MxBoardPriority::class,
];

$manager = $modx->getManager();

// До 3.0.0 у задачи был отдельный MEDIUMTEXT `tor`. В интерфейсе его давно заменили
// поля типа, но MCP продолжал складывать туда постановки. При обновлении переносим
// каждый непустой текст в обычный комментарий от автора задачи и только после
// проверки полного совпадения удаляем колонку.
//
// INSERT идемпотентен: если установка оборвётся до DROP COLUMN, повторный запуск
// увидит уже созданный комментарий по точному бинарному совпадению и не задублирует
// его. События/уведомления намеренно не генерируются — массовая миграция истории не
// является пользовательским комментированием.
try {
    $taskTable = $modx->getTableName(\MxBoard\Model\MxBoardTask::class);
    $commentTable = $modx->getTableName(\MxBoard\Model\MxBoardComment::class);
    $taskBare = trim((string) $taskTable, '`');
    $commentBare = trim((string) $commentTable, '`');

    $tableExists = static function (modX $modx, string $table): bool {
        if ($table === '') {
            return false;
        }
        $stmt = $modx->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);

        return (int) $stmt->fetchColumn() > 0;
    };
    $columnExists = static function (modX $modx, string $table, string $column): bool {
        $stmt = $modx->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    };

    if ($tableExists($modx, $taskBare) && $columnExists($modx, $taskBare, 'tor')) {
        if (!$tableExists($modx, $commentBare)) {
            throw new \RuntimeException('таблица комментариев не существует');
        }

        $sourceCount = (int) $modx->query(
            "SELECT COUNT(*) FROM {$taskTable} WHERE tor IS NOT NULL AND OCTET_LENGTH(tor) > 0"
        )->fetchColumn();

        if ($sourceCount > 0) {
            $insert = $modx->prepare(
                "INSERT INTO {$commentTable} (task_id, user_id, content, createdon) "
                . "SELECT t.id, t.author_id, t.tor, t.createdon FROM {$taskTable} t "
                . "WHERE t.tor IS NOT NULL AND OCTET_LENGTH(t.tor) > 0 "
                . "AND NOT EXISTS ("
                . "SELECT 1 FROM {$commentTable} c "
                . "WHERE c.task_id = t.id AND c.user_id = t.author_id AND BINARY c.content = BINARY t.tor"
                . ')'
            );
            if (!$insert->execute()) {
                throw new \RuntimeException('INSERT комментариев не выполнен');
            }
        }

        $unmatched = (int) $modx->query(
            "SELECT COUNT(*) FROM {$taskTable} t "
            . "WHERE t.tor IS NOT NULL AND OCTET_LENGTH(t.tor) > 0 "
            . "AND NOT EXISTS ("
            . "SELECT 1 FROM {$commentTable} c "
            . "WHERE c.task_id = t.id AND c.user_id = t.author_id AND BINARY c.content = BINARY t.tor"
            . ')'
        )->fetchColumn();

        if ($unmatched !== 0) {
            throw new \RuntimeException("не перенесено значений tor: {$unmatched} из {$sourceCount}");
        }

        $modx->exec("ALTER TABLE {$taskTable} DROP COLUMN `tor`");
        if ($columnExists($modx, $taskBare, 'tor')) {
            throw new \RuntimeException('DROP COLUMN tor не выполнен');
        }
        $modx->log(
            modX::LOG_LEVEL_INFO,
            "[mxBoard] Миграция tor → comments: перенесено/проверено {$sourceCount}, колонка удалена."
        );
    }
} catch (\Throwable $e) {
    // Не продолжаем upgrade без завершённого переноса: старый пакет и колонка с
    // исходными данными безопаснее частично установленной новой версии.
    $modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard] Миграция tor → comments остановлена: ' . $e->getMessage());

    return false;
}

// Миграции: ALTER TABLE ДО createObjectContainer (иначе xPDO падает на новом поле).
// Ключ — FQCN модели: имя таблицы из mxboard_task_type в класс через str_replace+ucfirst
// не разворачивается (дал бы MxBoardTask_type), поэтому маппим напрямую.
$migrations = [
    \MxBoard\Model\MxBoardColumn::class => [
        'ADD COLUMN `description` TEXT NULL AFTER `name`',
        'ADD COLUMN `color` VARCHAR(7) NOT NULL DEFAULT \'#6c757d\' AFTER `move_roles`',
        // stage_key убран: тег стадии нигде не использовался (см. фичу «колонки v2»).
        'DROP COLUMN `stage_key`',
        // Стартовая стадия: с неё идёт отсчёт фактического времени задачи.
        'ADD COLUMN `is_start` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `is_final`',
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
        // Учёт времени: план в целых часах (0 = не оценивали) + оспаривание оценки.
        // Факт отдельным полем не хранится — он считается из startedon/closedon.
        'ADD COLUMN `plan_hours` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `deadline_proposed`',
        'ADD COLUMN `plan_disputed` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `plan_hours`',
        'ADD COLUMN `plan_proposed` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `plan_disputed`',
        // Очереди задач: членство и порядок живут в самой задаче (максимум одна очередь
        // на задачу), отдельной таблицы связки нет — см. комментарий у mxboard_queue.
        'ADD COLUMN `queue_id` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `plan_proposed`',
        'ADD COLUMN `queue_position` INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `queue_id`',
        'ADD INDEX `queue_id` (`queue_id`)',
        'ADD INDEX `queue_position` (`queue_position`)',
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

// Сид справочника приоритетов: текущие четыре значения с цветами, эквивалентными
// прежним severity бейджа. INSERT IGNORE по уникальному value — не затирает правки
// оператора и не плодит дублей при повторной установке.
try {
    $priorityTable = $modx->getTableName(\MxBoard\Model\MxBoardPriority::class);
    if ($priorityTable) {
        $seed = [
            [0, 'Низкий', '#64748b'],
            [1, 'Обычный', '#3b82f6'],
            [2, 'Высокий', '#f59e0b'],
            [3, 'Критический', '#ef4444'],
        ];
        $now = time();
        $ins = $modx->prepare("INSERT IGNORE INTO {$priorityTable} (`name`, `color`, `value`, `createdon`) VALUES (?, ?, ?, ?)");
        foreach ($seed as [$value, $name, $color]) {
            $ins->execute([$name, $color, $value, $now]);
        }
        $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Справочник приоритетов засеян/на месте.');
    }
} catch (\Throwable $e) {
    $modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] Сид приоритетов: ' . $e->getMessage());
}

$modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Таблицы проверены/созданы.');

return true;
