<?php

return [
    // Доска по умолчанию: используется, когда клиент не указал board явно.
    'mxboard.default_board' => [
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
];
