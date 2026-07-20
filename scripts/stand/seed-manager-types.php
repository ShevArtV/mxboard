<?php

/**
 * Типы задач НАШЕГО менеджерского процесса — для нашего стенда, вне transport-пакета.
 *
 * Публичный пакет сеет нейтральный минимум (bugfix / feature / research): `promo_pricing`,
 * `seo` и прочее — это стандарт из knowledge-base/manager/task-workflow.md §1, и навязывать
 * его каждому, кто ставит mxBoard с modstore, неправильно. Поэтому наш набор ставится
 * отдельно этим скриптом и в поставку не входит.
 *
 * Запуск на стенде:
 *   /usr/local/php/php-8.3/bin/php scripts/stand/seed-manager-types.php
 *
 * Состав типов НЕ объявлен здесь: он лежит в core/components/mxboard/schema/task-types.php,
 * общем с резолвером пакета. Раньше списка было два и они разъехались.
 *
 * Идемпотентен: существующие типы и поля не дублирует, значения задач не теряет.
 * Заодно чинит расхождения, накопившиеся на стенде:
 *   - research.promt → prompt (опечатка в ключе; значения переносятся в fields задач);
 *   - bugfix.file (тип `file`, которого нет в FIELD_TYPES) → files;
 *   - bugfix.file → attachments (единый ключ вложений во всех типах).
 */

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;

define('MODX_API_MODE', true);

require_once __DIR__ . '/../../config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbseedtypes');
$modx->initialize('mgr');

$corePath = MODX_CORE_PATH . 'components/mxboard/';
if (is_file($corePath . 'vendor/autoload.php')) {
    require_once $corePath . 'vendor/autoload.php';
}
if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$now = time();

/** @var MxBoardDepartment|null $department */
$department = $modx->getObject(MxBoardDepartment::class, ['active' => true]);
if (!$department) {
    exit("Отдел не найден — сначала поставьте пакет.\n");
}
$departmentId = (int) $department->get('id');

/* --- 1. Миграция расхождений, накопившихся на стенде ------------------------- */

/**
 * Переименовать ключ поля вместе со значениями в задачах. Значения живут в JSON
 * `mxboard_task.fields`, поэтому переименование только в справочнике потеряло бы данные.
 */
$renameField = static function (string $typeKey, string $from, string $to) use ($modx, $departmentId): void {
    /** @var MxBoardTaskType|null $type */
    $type = $modx->getObject(MxBoardTaskType::class, ['department_id' => $departmentId, 'key' => $typeKey]);
    if (!$type) {
        return;
    }
    /** @var MxBoardField|null $field */
    $field = $modx->getObject(MxBoardField::class, ['task_type_id' => $type->get('id'), 'key' => $from]);
    if (!$field) {
        return;
    }
    $field->set('key', $to);
    $field->save();

    $q = $modx->newQuery(MxBoardTask::class);
    $q->where(['task_type_id' => $type->get('id')]);
    foreach ($modx->getIterator(MxBoardTask::class, $q) as $task) {
        $fields = $task->get('fields');
        if (!is_array($fields) || !array_key_exists($from, $fields)) {
            continue;
        }
        $fields[$to] = $fields[$from];
        unset($fields[$from]);
        $task->set('fields', $fields);
        $task->save();
    }
    echo "  migrate: {$typeKey}.{$from} → {$to}\n";
};

$renameField('research', 'promt', 'prompt');

// Тип поля `file` — мёртвая ветка: в FIELD_TYPES его нет, поле не создать через API.
// Файловая зона задачи называется `files`, на неё и переводим.
foreach ($modx->getIterator(MxBoardField::class, ['type' => 'file']) as $field) {
    $field->set('type', 'files');
    $field->save();
    echo "  migrate: поле #{$field->get('id')} ({$field->get('key')}) type file → files\n";
}

// Ключ `file` остался от мёртвого типа поля. Единый ключ вложений во всех типах —
// `attachments` (как в feature), иначе на каждый тип свой синоним одного и того же.
$renameField('bugfix', 'file', 'attachments');

/* --- 2. Типы менеджерского процесса ----------------------------------------- */

// Состав по knowledge-base/manager/task-workflow.md §1 — читается из общего файла
// core/components/mxboard/schema/task-types.php, который же использует резолвер пакета.
// Берём оба набора: `core` доберёт недостающие поля к типам из поставки
// (bugfix.severity, feature.implementation…), `manager` создаст наши доменные типы.
$schemaFile = $corePath . 'schema/task-types.php';
if (!is_file($schemaFile)) {
    exit("Не найден {$schemaFile} — сначала выкатите пакет.\n");
}
$schema = require $schemaFile;
$types = ($schema['core'] ?? []) + ($schema['manager'] ?? []);


$position = 10;
foreach ($types as $key => $data) {
    /** @var MxBoardTaskType|null $type */
    $type = $modx->getObject(MxBoardTaskType::class, ['department_id' => $departmentId, 'key' => $key]);
    if (!$type) {
        /** @var MxBoardTaskType $type */
        $type = $modx->newObject(MxBoardTaskType::class);
        $type->fromArray([
            'department_id' => $departmentId,
            'key' => $key,
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'active' => true,
            'position' => $position++,
            'createdon' => $now,
        ]);
        if (!$type->save()) {
            echo "  FAIL: тип {$key} не создан\n";
            continue;
        }
        echo "  type +{$key}\n";
    }

    $typeId = (int) $type->get('id');
    $maxPos = 0;
    foreach ($modx->getIterator(MxBoardField::class, ['task_type_id' => $typeId]) as $existing) {
        $maxPos = max($maxPos, (int) $existing->get('position'));
    }

    foreach ($data['fields'] as $fieldData) {
        /** @var MxBoardField|null $existingField */
        $existingField = $modx->getObject(MxBoardField::class, ['task_type_id' => $typeId, 'key' => $fieldData['key']]);
        if ($existingField) {
            // Поле есть — подтягиваем его описание к схеме. Иначе стенд снова разъедется
            // с поставкой: так, после переименования bugfix.file → attachments подпись
            // осталась «Файл», хотя в схеме давно «Материалы».
            // Поля вне схемы (execution_thread) не трогаем — их тут просто нет.
            $changed = [];
            foreach (['label', 'type', 'required'] as $prop) {
                if ($existingField->get($prop) != $fieldData[$prop]) {
                    $existingField->set($prop, $fieldData[$prop]);
                    $changed[] = $prop;
                }
            }
            $options = $fieldData['options'] ?? null;
            if ($existingField->get('options') != $options) {
                $existingField->set('options', $options);
                $changed[] = 'options';
            }
            if ($changed) {
                $existingField->save();
                echo "    sync {$key}.{$fieldData['key']}: " . implode(', ', $changed) . "\n";
            }
            continue;
        }
        /** @var MxBoardField $field */
        $field = $modx->newObject(MxBoardField::class);
        $field->fromArray([
            'task_type_id' => $typeId,
            'key' => $fieldData['key'],
            'label' => $fieldData['label'],
            'type' => $fieldData['type'],
            'required' => $fieldData['required'],
            'position' => ++$maxPos,
            'options' => $fieldData['options'] ?? null,
            'createdon' => $now,
        ]);
        $field->save();
        echo "    field {$key}.{$fieldData['key']}\n";
    }
}

echo "Готово.\n";
