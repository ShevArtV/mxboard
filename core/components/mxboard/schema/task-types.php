<?php

/**
 * Единый источник правды по типам задач и их полям.
 *
 * Зачем файл. Раньше состав типов был объявлен дважды: в резолвере пакета
 * (`package_builder/.../02.resolve.board.php`) и в стендовом сиде
 * (`scripts/stand/seed-manager-types.php`). Два независимых списка неизбежно
 * разъезжаются — что и произошло: резолвер сеял `bugfix` без `environment`/`severity`,
 * `research` с полем `result_format`, которого на стенде нет, и не знал про `select`
 * вовсе. Теперь оба потребителя читают этот файл.
 *
 * Два набора, а не один — намеренно:
 *
 *  - `core` — нейтральный минимум, который ставится ВСЕМ из transport-пакета.
 *    Багфикс, фича, ресёрч осмысленны в любом проекте.
 *  - `manager` — типы нашего менеджерского процесса по
 *    knowledge-base/manager/task-workflow.md §1 (акции и цены, SEO, вёрстка…).
 *    Навязывать «Акции и цены» каждому, кто ставит mxBoard с modstore, неправильно:
 *    это стандарт конкретного отдела, а не функциональность доски. Ставится
 *    отдельно стендовым сидом и в поставку не входит.
 *
 * Формат поля: key, label, type, required, опционально options (для `select`).
 * Допустимые типы — StructureService::FIELD_TYPES:
 * text, textarea, url, number, date, select, user, files.
 *
 * Порядок полей в массиве = порядок в форме постановки (position).
 */

return [
    /* --- Публичный минимум: едет в transport-пакете ------------------------- */
    'core' => [
        'bugfix' => [
            'name' => 'Багфикс',
            'description' => 'Исправление ошибки: где, что, как воспроизвести и как должно быть.',
            'fields' => [
                ['key' => 'where', 'label' => 'Где сломалось', 'type' => 'text', 'required' => true],
                ['key' => 'what', 'label' => 'Что сломалось', 'type' => 'textarea', 'required' => true],
                ['key' => 'steps', 'label' => 'Как воспроизвести', 'type' => 'textarea', 'required' => true],
                ['key' => 'expected', 'label' => 'Как должно быть', 'type' => 'textarea', 'required' => true],
                ['key' => 'environment', 'label' => 'Окружение', 'type' => 'text', 'required' => true],
                ['key' => 'severity', 'label' => 'Severity', 'type' => 'select', 'required' => true,
                    'options' => ['critical', 'major', 'minor', 'cosmetic']],
                ['key' => 'attachments', 'label' => 'Материалы', 'type' => 'files', 'required' => false],
            ],
        ],
        'feature' => [
            'name' => 'Фича',
            'description' => 'Новая функциональность: цель, реализация и критерии приёмки.',
            'fields' => [
                ['key' => 'goal', 'label' => 'Цель', 'type' => 'textarea', 'required' => true],
                ['key' => 'implementation', 'label' => 'Описание реализации', 'type' => 'textarea', 'required' => true],
                ['key' => 'criteria', 'label' => 'Критерии приёмки', 'type' => 'textarea', 'required' => true],
                ['key' => 'contexts', 'label' => 'Страны/контексты', 'type' => 'text', 'required' => true],
                ['key' => 'dependencies', 'label' => 'Зависимости', 'type' => 'textarea', 'required' => true],
                ['key' => 'reference', 'label' => 'Ссылка на аналог', 'type' => 'url', 'required' => false],
                ['key' => 'attachments', 'label' => 'Материалы', 'type' => 'files', 'required' => false],
            ],
        ],
        'research' => [
            'name' => 'Исследование',
            'description' => 'Изучить вопрос и вернуть ответ: что выяснить и в каком виде отдать результат.',
            'fields' => [
                ['key' => 'prompt', 'label' => 'Промт', 'type' => 'textarea', 'required' => true],
                ['key' => 'result_format', 'label' => 'Формат результата', 'type' => 'textarea', 'required' => false],
            ],
        ],
    ],

    /* --- Менеджерский процесс: только наш стенд ----------------------------- */
    'manager' => [
        'integration' => [
            'name' => 'Интеграция',
            'description' => 'Внешний сервис: API, платёжка, webhook. Часть полей заполняет разработчик на триаже.',
            'fields' => [
                ['key' => 'goal', 'label' => 'Что хотим получить', 'type' => 'textarea', 'required' => true],
                ['key' => 'service', 'label' => 'Сторонний сервис', 'type' => 'text', 'required' => true],
                ['key' => 'support', 'label' => 'Контакты техподдержки', 'type' => 'text', 'required' => true],
                ['key' => 'contract', 'label' => 'Договор/тариф', 'type' => 'textarea', 'required' => false],
                // Ниже — триаж разработчика: на постановке их ещё неоткуда взять, поэтому не required.
                // Иначе менеджер не сможет завести карточку до ресёрча — это ломает процесс.
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
            'description' => 'Оптимизация выдачи и скорости.',
            'fields' => [
                ['key' => 'goal', 'label' => 'Цель', 'type' => 'textarea', 'required' => true],
                ['key' => 'metrics_before', 'label' => 'Текущие метрики', 'type' => 'textarea', 'required' => true],
                ['key' => 'metrics_after', 'label' => 'Ожидаемый эффект', 'type' => 'textarea', 'required' => true],
                ['key' => 'verify', 'label' => 'Метод проверки', 'type' => 'textarea', 'required' => true],
            ],
        ],
        // Ключ намеренно `update`, а не `update_change`: ключ — часть API (task_create),
        // на него уже могут ссылаться внешние вызовы и существующие карточки.
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
    ],
];
