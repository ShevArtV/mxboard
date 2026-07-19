<?php

/**
 * Статические плагины mxBoard. Код лежит на диске (static: plugins=true) и
 * подключается по 'content: file:...' относительно core-пути пакета.
 */
return [
    'mxBoardProfileToken' => [
        'description' => 'Виджет «Токен агента» на странице профиля пользователя в менеджере (только sudo). Выдаёт/перевыпускает один токен на пользователя для доступа агента к REST/MCP.',
        'content' => 'file:elements/plugins/plugin.mxboardprofiletoken.php',
        'events' => [
            'OnManagerPageBeforeRender',
        ],
    ],
    'mxBoardKiosk' => [
        'description' => 'Киоск-режим: рядового (не sudo) члена группы из настройки mxboard.kiosk_usergroups при входе в менеджер редиректит сразу на канбан. По умолчанию настройка пуста — никого не трогает.',
        'content' => 'file:elements/plugins/plugin.mxboardkiosk.php',
        'events' => [
            'OnManagerPageInit',
        ],
    ],
];
