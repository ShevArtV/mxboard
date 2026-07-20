<?php

/**
 * Поле `execution_thread` — временный Jarvis-топик задачи. Для нашего стенда,
 * вне transport-пакета.
 *
 * Зачем. jarvis-mxboard-poller по умолчанию отправляет задачу в топик проекта
 * (описание проекта = `<путь>|<thread_id>`). Один постоянный топик на проект плохо
 * держит параллельную работу: пока в нём идёт объёмная фича, срочный багфикс ждать
 * не должен. Заполненный `execution_thread` уводит задачу во временный топик,
 * пустой — штатный fallback на дефолт проекта.
 *
 * Почему вне пакета: привязка к Jarvis — наш процесс, а не функциональность mxBoard.
 * Каталог scripts/ не входит в transport (билдер собирает только core/ и assets/),
 * поэтому тем, кто ставит пакет с modstore, это поле не навяжется.
 *
 * Запуск на стенде (из корня www/):
 *   /usr/local/php/php-8.3/bin/php scripts/stand/seed-execution-thread-field.php
 *
 * Идемпотентен: повторный запуск ничего не создаёт и не меняет. Существующие поля и
 * типы не трогает вовсе — только добавляет своё, если его ещё нет.
 */

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardTaskType;

define('MODX_API_MODE', true);

require_once __DIR__ . '/../../config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbseedexecthread');
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

// Типы, которые реально уходят ИИ-исполнителю и могут конкурировать за топик проекта.
// Остальные (layout, content, promo_pricing, seo, configuration, update, integration)
// пока ведут люди — добавить их можно, дописав ключ в этот список и перезапустив скрипт.
$targetTypes = ['bugfix', 'feature', 'research'];

// type `text`, а не `number`: thread_id — идентификатор, а не величина. Плюс `number`
// в UI даёт спиннер и локальное форматирование, из-за которого в поле может уехать
// «9 411». Поллер валидирует значение как ^\d+$ и на мусор шлёт алерт Менеджеру.
$fieldDefinition = [
    'key' => 'execution_thread',
    'label' => 'Временный топик (thread_id)',
    'type' => 'text',
    'required' => false,
];

foreach ($targetTypes as $typeKey) {
    /** @var MxBoardTaskType|null $type */
    $type = $modx->getObject(MxBoardTaskType::class, [
        'department_id' => $departmentId,
        'key' => $typeKey,
    ]);
    if (!$type) {
        echo "  skip: типа {$typeKey} нет в отделе #{$departmentId}\n";
        continue;
    }

    $typeId = (int) $type->get('id');
    if ($modx->getObject(MxBoardField::class, ['task_type_id' => $typeId, 'key' => $fieldDefinition['key']])) {
        echo "  ok: {$typeKey}.{$fieldDefinition['key']} уже есть\n";
        continue;
    }

    // В конец списка полей: поле служебное и необязательное, лезть им в середину
    // формы постановки незачем.
    $maxPos = 0;
    foreach ($modx->getIterator(MxBoardField::class, ['task_type_id' => $typeId]) as $existing) {
        $maxPos = max($maxPos, (int) $existing->get('position'));
    }

    /** @var MxBoardField $field */
    $field = $modx->newObject(MxBoardField::class);
    $field->fromArray([
        'task_type_id' => $typeId,
        'key' => $fieldDefinition['key'],
        'label' => $fieldDefinition['label'],
        'type' => $fieldDefinition['type'],
        'required' => $fieldDefinition['required'],
        'position' => $maxPos + 1,
        'options' => null,
        'createdon' => $now,
    ]);
    if (!$field->save()) {
        echo "  FAIL: {$typeKey}.{$fieldDefinition['key']} не сохранено\n";
        continue;
    }
    echo "  field +{$typeKey}.{$fieldDefinition['key']}\n";
}

echo "Готово.\n";
