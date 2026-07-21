<?php

return [
    // Шаблон человекочитаемого номера задачи (num). Плейсхолдеры: {Y} год-4, {y} год-2,
    // {m} месяц, {d} день, {num} — счётчик периода. Период сброса счётчика — по самому
    // мелкому date-плейсхолдеру: {d}→за сутки, {m}→за месяц, {y}→за год. По умолчанию
    // «{y}{m}-{num}» → 2607-1 (год+месяц, сквозной счётчик за месяц по всей системе).
    'mxboard.task_num_format' => [
        'xtype' => 'textfield',
        'value' => '{y}{m}-{num}',
        'area' => 'mxboard_main',
    ],
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
    // В MODX меньше authority = больше прав; 1 = только роль верхнего уровня.
    'mxboard.group_admin_authority' => [
        'xtype' => 'numberfield',
        'value' => '1',
        'area' => 'mxboard_rights',
    ],
    // Киоск-режим (плагин mxBoardKiosk): CSV имён групп MODX, чьих рядовых (не sudo)
    // членов при входе в менеджер редиректить сразу на канбан. Пусто — выключено.
    // Обычно сюда кладут группы-отделы с назначенной политикой «mxBoard Only».
    'mxboard.kiosk_usergroups' => [
        'xtype' => 'textfield',
        'value' => '',
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

    // --- In-app уведомления (SSE) ---
    // Живой поток уведомлений доски в админку через Server-Sent Events. Выключение
    // гасит и генерацию строк уведомлений (NotificationService), и SSE-эндпоинт.
    'mxboard.sse_enabled' => [
        'xtype' => 'combo-boolean',
        'value' => '1',
        'area' => 'mxboard_main',
    ],
    // Время жизни одного SSE-соединения, сек (клиент переподключается сам с Last-Event-ID).
    // Держим коротким: на shared-хостинге долгие запросы рвут прокси/FPM.
    'mxboard.sse_lifetime' => [
        'xtype' => 'numberfield',
        'value' => '25',
        'area' => 'mxboard_main',
    ],
    // Интервал опроса новых уведомлений внутри соединения, сек.
    'mxboard.sse_poll_interval' => [
        'xtype' => 'numberfield',
        'value' => '3',
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
