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
    // Медиа-источник MODX для вложений задач/комментов и полей-файлов. Заполняется
    // резолвером 03 при установке (выделенный источник «mxBoard»). 0 — источник по умолчанию.
    'mxboard.media_source' => [
        'xtype' => 'numberfield',
        'value' => '0',
        'area' => 'mxboard_main',
    ],
    // Максимальный размер вложения, байт (0 — без своего лимита, действует лимит источника/MODX).
    'mxboard.upload_max_size' => [
        'xtype' => 'numberfield',
        'value' => '10485760',
        'area' => 'mxboard_main',
    ],
    // Сколько файлов можно приложить за один раз (батч drag-n-drop/выбора). 0 — без лимита.
    'mxboard.upload_max_files' => [
        'xtype' => 'numberfield',
        'value' => '10',
        'area' => 'mxboard_main',
    ],
    // Разрешённые расширения вложений через запятую (пусто — не ограничиваем на стороне mxBoard;
    // источник всё равно применит свой allowedFileTypes / системный upload_files).
    'mxboard.upload_extensions' => [
        'xtype' => 'textfield',
        'value' => 'jpg,jpeg,png,gif,webp,svg,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,md,csv,zip,rar,7z,log,json',
        'area' => 'mxboard_main',
    ],

    // --- ИИ-проверка полноты постановки задач ---
    // Базовый URL OpenAI-совместимого эндпоинта (клиент добавит /chat/completions).
    // Работает с любым провайдером: mimo, OpenAI, DeepSeek, локальные vLLM/Ollama.
    'mxboard.ai_base_url' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mxboard_ai',
    ],
    // Ключ доступа к провайдеру (Bearer). Пусто — проверка выключена (fail-open).
    'mxboard.ai_api_key' => [
        'xtype' => 'textfield',
        'value' => '',
        'area' => 'mxboard_ai',
    ],
    // Имя модели: mimo-v2.5, gpt-4o-mini, deepseek-chat и т.п.
    'mxboard.ai_model' => [
        'xtype' => 'textfield',
        'value' => 'mimo-v2.5',
        'area' => 'mxboard_ai',
    ],
    // Режим гейта: strict — неполную задачу создать нельзя; soft — предупреждение
    // с возможностью «всё равно создать».
    'mxboard.ai_check_mode' => [
        'xtype' => 'textfield',
        'value' => 'strict',
        'area' => 'mxboard_ai',
    ],
    // Глобальный промпт-шаблон проверки. Тип может переопределить его своим ai_prompt.
    'mxboard.ai_check_prompt' => [
        'xtype' => 'textarea',
        'value' => 'Ты — рецензент постановки задач в трекере. Оцени, достаточно ли в задаче информации, '
            . 'чтобы исполнитель мог сразу взяться за работу, не переспрашивая. Ориентируйся на поля типа задачи: '
            . 'каждое поле — это вопрос, на который постановка обязана ответить конкретно (например, для багфикса: где именно '
            . 'ошибка — ссылка или место в коде, что именно не так, как воспроизвести, ожидаемое поведение). '
            . 'Размытые формулировки уровня «сломался сайт, не работает оплата» считай неполными. '
            . 'Верни СТРОГО JSON без пояснений: {"complete": true|false, "score": 0-100, '
            . '"missing": ["чего конкретно не хватает", ...], "summary": "краткий вердикт одной фразой"}.',
        'area' => 'mxboard_ai',
    ],
];
