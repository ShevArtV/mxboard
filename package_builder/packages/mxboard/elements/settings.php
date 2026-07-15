<?php

return [
    // Проект по умолчанию: используется, когда клиент не указал project явно.
    'mxboard.default_project' => [
        'xtype' => 'textfield',
        'value' => 'default',
        'area' => 'mxboard_main',
    ],
    // REST-API для агентов.
    'mxboard.api_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => '1',
        'area' => 'mxboard_api',
    ],
    // MCP-эндпоинт (JSON-RPC) для агентов.
    'mxboard.mcp_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => '1',
        'area' => 'mxboard_api',
    ],
    // Разрешить закрывать карточку, где автор и исполнитель — один пользователь.
    // По умолчанию запрещено: иначе агент сам себе ставит «сделано».
    'mxboard.allow_self_close' => [
        'xtype' => 'combo-boolean',
        'value' => '0',
        'area' => 'mxboard_rights',
    ],
    // Сколько задач один исполнитель может держать в работе одновременно (0 — без лимита).
    'mxboard.wip_limit' => [
        'xtype' => 'numberfield',
        'value' => '0',
        'area' => 'mxboard_rights',
    ],
    // Порог authority роли, при котором член группы отдела считается её супер-пользователем.
    // В MODX меньше authority = больше прав; 0 = фича выключена (штатный минимум authority = 1).
    'mxboard.group_admin_authority' => [
        'xtype' => 'numberfield',
        'value' => '0',
        'area' => 'mxboard_rights',
    ],
    // Медиа-источник MODX для загрузки файлов в поля-файлы (0 — источник по умолчанию).
    'mxboard.media_source' => [
        'xtype' => 'numberfield',
        'value' => '0',
        'area' => 'mxboard_main',
    ],
];
