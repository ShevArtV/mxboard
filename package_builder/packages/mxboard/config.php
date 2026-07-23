<?php

return [
    'name' => 'mxBoard',
    'name_lower' => 'mxboard',
    'name_short' => 'mxb',
    'version' => '2.10.1',
    'release' => 'pl',
    'php_version' => '8.1',

    'paths' => [
        'core' => 'core/components/mxboard/',
        'assets' => 'assets/components/mxboard/',
    ],

    // Префикс задан явно: по умолчанию генератор берёт его из 'name' (mxBoard),
    // а в схеме пакет — MxBoard\Model. Регистр не совпадает, префикс не отрезается,
    // и классы уезжают в src/MxBoard/Model вместо src/Model.
    'namespace_prefix' => 'MxBoard\\',

    'schema' => [
        'file' => 'schema/mxboard.mysql.schema.xml',
        'auto_generate_classes' => true,
        'update_tables' => false,
    ],

    'elements' => [
        'category' => 'mxBoard',
        'settings' => 'elements/settings.php',
        'events' => 'elements/events.php',
        'menus' => 'elements/menus.php',
        'plugins' => 'elements/plugins.php',
        'snippets' => 'elements/snippets.php',
    ],

    'static' => [
        'chunks' => false,
        'snippets' => false,
        'plugins' => true,
    ],

    'encrypt' => false,

    'tools' => [
        'analyse' => 'vendor/bin/phpstan analyse --no-progress',
        'cs' => 'vendor/bin/php-cs-fixer fix',
        'csMode' => 'fix',
    ],

    'build' => [
        'download' => false,
        'install' => false,
        // Настройки при апгрейде не перезаписываем — иначе слетят значения пользователя.
        'update' => [
            'plugins' => true,
            'settings' => false,
            'events' => true,
            'menus' => true,
        ],
    ],
];
