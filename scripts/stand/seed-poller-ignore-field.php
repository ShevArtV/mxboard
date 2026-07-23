<?php

/**
 * Поле `poller_ignore` — выключатель jarvis-mxboard-poller на конкретной задаче.
 * Для нашего стенда, вне transport-пакета.
 *
 * Зачем. Часть задач уходит в работу через Telegram-бота (там поллер и нужен), а часть
 * оператор ведёт сам, руками. По вторым будить агентов незачем: заполненное «Да»
 * гасит по задаче ВСЁ — trigger исполнителю, trigger Менеджеру и алерт о непривязанном
 * проекте.
 *
 * Дефолта у полей типа в mxBoard нет (в `mxboard_field` нет такой колонки, а форма
 * постановки стартует с пустым `fields`), поэтому «по умолчанию Нет» реализовано
 * поведением поллера: игнор включается ТОЛЬКО явным «Да», пустое/«Нет»/непонятное
 * значение = работаем как обычно. Fail-open — лишний trigger оператор увидит и
 * погасит, а молча пропавший запуск не увидит никто.
 *
 * Почему вне пакета: привязка к Jarvis — наш процесс, а не функциональность mxBoard.
 * Каталог scripts/ не входит в transport (билдер собирает только core/ и assets/),
 * поэтому тем, кто ставит пакет с modstore, это поле не навяжется. Та же логика, что у
 * соседнего seed-execution-thread-field.php.
 *
 * Запуск на стенде (из корня www/):
 *   /usr/local/php/php-8.3/bin/php scripts/stand/seed-poller-ignore-field.php
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

$modx = modX::getInstance('mxbseedpollerignore');
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

// ВСЕ типы отдела, а не фиксированный список: вручную оператор может вести задачу
// любого типа, а типы на стенде заводятся в том числе руками. Список в коде тут же
// отставал бы от доски, поэтому источник — сама БД.
$targetTypes = [];
foreach ($modx->getIterator(MxBoardTaskType::class, ['department_id' => $departmentId]) as $type) {
    $targetTypes[] = (string) $type->get('key');
}
sort($targetTypes);

// type `select`: варианты фиксированы, и выпадашка не даёт опечататься («ага»,
// «yes!»), которую поллер по своему fail-open прочитает как «работаем». «Нет» первым
// в options — оно и есть штатное состояние задачи.
$fieldDefinition = [
    'key' => 'poller_ignore',
    'label' => 'Игнорировать в поллере',
    'type' => 'select',
    'required' => false,
    'options' => ['Нет', 'Да'],
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
        'options' => $fieldDefinition['options'],
        'createdon' => $now,
    ]);
    if (!$field->save()) {
        echo "  FAIL: {$typeKey}.{$fieldDefinition['key']} не сохранено\n";
        continue;
    }
    echo "  field +{$typeKey}.{$fieldDefinition['key']}\n";
}

echo "Готово.\n";
