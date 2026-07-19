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
 * Идемпотентен: существующие типы и поля не дублирует, значения задач не теряет.
 * Заодно чинит два расхождения, накопившихся на стенде:
 *   - research.promt → prompt (опечатка в ключе; значения переносятся в fields задач);
 *   - bugfix.file (тип `file`, которого нет в FIELD_TYPES) → files.
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

/* --- 2. Типы менеджерского процесса ----------------------------------------- */

// Состав по knowledge-base/manager/task-workflow.md §1. Типы bugfix/feature/research
// уже создаёт резолвер пакета — здесь только доборные поля к ним.
$types = [
    'bugfix' => [
        'name' => 'Багфикс',
        'fields' => [
            ['key' => 'environment', 'label' => 'Окружение', 'type' => 'text', 'required' => true],
            ['key' => 'severity', 'label' => 'Severity', 'type' => 'select', 'required' => true,
                'options' => ['critical', 'major', 'minor', 'cosmetic']],
        ],
    ],
    'feature' => [
        'name' => 'Фича',
        'fields' => [
            ['key' => 'implementation', 'label' => 'Описание реализации', 'type' => 'textarea', 'required' => true],
            ['key' => 'reference', 'label' => 'Ссылка на аналог', 'type' => 'url', 'required' => false],
            ['key' => 'contexts', 'label' => 'Страны/контексты', 'type' => 'text', 'required' => true],
            ['key' => 'dependencies', 'label' => 'Зависимости', 'type' => 'textarea', 'required' => true],
        ],
    ],
    'integration' => [
        'name' => 'Интеграция',
        'description' => 'Внешний сервис: API, платёжка, webhook. Часть полей заполняет разработчик на триаже.',
        'fields' => [
            ['key' => 'goal', 'label' => 'Что хотим получить', 'type' => 'textarea', 'required' => true],
            ['key' => 'service', 'label' => 'Сторонний сервис', 'type' => 'text', 'required' => true],
            ['key' => 'support', 'label' => 'Контакты техподдержки', 'type' => 'text', 'required' => true],
            ['key' => 'contract', 'label' => 'Договор/тариф', 'type' => 'textarea', 'required' => false],
            // Ниже — триаж разработчика: на постановке их ещё неоткуда взять, поэтому не required.
            ['key' => 'api_docs', 'label' => 'Документация API', 'type' => 'url', 'required' => false],
            ['key' => 'protocol', 'label' => 'Протокол', 'type' => 'select', 'required' => false,
                'options' => ['REST', 'SOAP', 'GraphQL', 'SDK']],
            ['key' => 'integration_type', 'label' => 'Тип интеграции', 'type' => 'select', 'required' => false,
                'options' => ['API', 'webhook', 'iFrame']],
            ['key' => 'data_flow', 'label' => 'Что передаём/получаем', 'type' => 'textarea', 'required' => false],
            ['key' => 'sandbox', 'label' => 'Доступы к тесту', 'type' => 'textarea', 'required' => false],
            ['key' => 'prod_access', 'label' => 'Prod-доступы (где хранятся)', 'type' => 'textarea', 'required' => false],
            ['key' => 'errors', 'label' => 'Обработка ошибок', 'type' => 'textarea', 'required' => false],
            ['key' => 'complexity', 'label' => 'Сложность', 'type' => 'select', 'required' => false,
                'options' => ['S', 'M', 'L', 'XL']],
        ],
    ],
    'layout' => [
        'name' => 'Вёрстка',
        'description' => 'Лендинг, баннер, вёрстка по макету.',
        'fields' => [
            ['key' => 'mockup', 'label' => 'Ссылка на макет', 'type' => 'url', 'required' => true],
            ['key' => 'copy', 'label' => 'Текст', 'type' => 'textarea', 'required' => true],
            ['key' => 'deadline_note', 'label' => 'Срок', 'type' => 'text', 'required' => true],
            ['key' => 'placement', 'label' => 'Где разместить', 'type' => 'text', 'required' => true],
            ['key' => 'approver', 'label' => 'Кто утверждает', 'type' => 'user', 'required' => true],
        ],
    ],
    'content' => [
        'name' => 'Контент',
        'description' => 'Тексты, фото, медиа.',
        'fields' => [
            ['key' => 'target', 'label' => 'Что меняем', 'type' => 'text', 'required' => true],
            ['key' => 'old_text', 'label' => 'Старый текст', 'type' => 'textarea', 'required' => true],
            ['key' => 'new_text', 'label' => 'Новый текст', 'type' => 'textarea', 'required' => true],
            ['key' => 'source', 'label' => 'Где взять', 'type' => 'text', 'required' => true],
            ['key' => 'seo', 'label' => 'SEO-требования', 'type' => 'textarea', 'required' => false],
        ],
    ],
    'promo_pricing' => [
        'name' => 'Акции и цены',
        'description' => 'Скидки, промокоды, изменение цен.',
        'fields' => [
            ['key' => 'promo_type', 'label' => 'Тип', 'type' => 'select', 'required' => true,
                'options' => ['скидка %', 'фиксированная цена', 'промокод', 'товар недели']],
            ['key' => 'amount', 'label' => 'Размер скидки', 'type' => 'text', 'required' => true],
            ['key' => 'period', 'label' => 'Сроки (start — end, таймзона)', 'type' => 'text', 'required' => true],
            ['key' => 'contexts', 'label' => 'Страны/контексты', 'type' => 'text', 'required' => true],
            ['key' => 'products', 'label' => 'Товары (SKU/артикулы)', 'type' => 'textarea', 'required' => true],
            ['key' => 'conditions', 'label' => 'Условия', 'type' => 'textarea', 'required' => false],
        ],
    ],
    'configuration' => [
        'name' => 'Настройка',
        'description' => 'Параметры сайта, сервера, модуля.',
        'fields' => [
            ['key' => 'what', 'label' => 'Что настроить', 'type' => 'text', 'required' => true],
            ['key' => 'where', 'label' => 'Где', 'type' => 'text', 'required' => true],
            ['key' => 'values', 'label' => 'Значения', 'type' => 'textarea', 'required' => true],
            ['key' => 'expected', 'label' => 'Ожидаемый результат', 'type' => 'textarea', 'required' => true],
        ],
    ],
    'seo' => [
        'name' => 'SEO и оптимизация',
        'fields' => [
            ['key' => 'goal', 'label' => 'Цель', 'type' => 'textarea', 'required' => true],
            ['key' => 'metrics_before', 'label' => 'Текущие метрики', 'type' => 'textarea', 'required' => true],
            ['key' => 'metrics_after', 'label' => 'Ожидаемый эффект', 'type' => 'textarea', 'required' => true],
            ['key' => 'verify', 'label' => 'Метод проверки', 'type' => 'textarea', 'required' => true],
        ],
    ],
    'update' => [
        'name' => 'Обновление',
        'description' => 'Правка существующего кода или данных.',
        'fields' => [
            ['key' => 'what', 'label' => 'Что меняется', 'type' => 'text', 'required' => true],
            ['key' => 'reason', 'label' => 'Причина изменения', 'type' => 'textarea', 'required' => true],
            ['key' => 'regression_risk', 'label' => 'Риск регрессии', 'type' => 'textarea', 'required' => true],
            ['key' => 'reviewer', 'label' => 'Кто проверяет', 'type' => 'user', 'required' => true],
        ],
    ],
];

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
        if ($modx->getObject(MxBoardField::class, ['task_type_id' => $typeId, 'key' => $fieldData['key']])) {
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
