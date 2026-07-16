<?php

/**
 * mxBoardProfileToken — виджет «Токен агента» на странице профиля пользователя.
 *
 * На OnManagerPageBeforeRender, только на страницах security/user/update|create и
 * только для sudo (раздавать доступ агентам вправе суперадмин): грузит vanilla-JS
 * виджет и прокидывает конфиг + лексикон. Виджет вставляет в форму профиля секцию с
 * текущим токеном и кнопкой «Сгенерировать/Перевыпустить» (процессоры Token/*ForUser).
 *
 * @var \MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */

use MODX\Revolution\modX;

if ($modx->event->name !== 'OnManagerPageBeforeRender') {
    return;
}

$user = $modx->user;
if (!$user || !(bool) $user->get('sudo')) {
    return;
}

// Только страницы пользователя (создание/правка). Экономим: на прочих не грузим.
$action = (string) ($_GET['a'] ?? '');
if ($action !== 'security/user/update' && $action !== 'security/user/create') {
    return;
}

if (!isset($modx->controller) || !is_object($modx->controller)) {
    return;
}

$assetsUrl = $modx->getOption('assets_url', null, MODX_ASSETS_URL) . 'components/mxboard/';

$config = [
    'connector_url' => $assetsUrl . 'connector.php',
    'token' => (string) $user->getUserToken($modx->context->get('key')),
    // id пользователя со страницы update; на create его ещё нет — виджет попросит сохранить.
    'user_id' => (int) ($_GET['id'] ?? 0),
];
$json = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Лексикон в JS: строки виджета не хардкодятся, берутся из MODx.lang (как во Vue-части).
$modx->lexicon->load('mxboard:default');
$lang = $modx->lexicon->fetch('mxboard');
$langJson = json_encode($lang, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$modx->controller->addHtml(
    '<script>'
    . 'window.MxBoardProfileToken = ' . $json . ';'
    . 'window.MODx=window.MODx||{};MODx.lang=Object.assign(MODx.lang||{}, ' . $langJson . ');'
    . '</script>'
);
$modx->controller->addLastJavascript($assetsUrl . 'js/mgr/profile-token.js?v=' . urlencode((string) $modx->getOption('mxboard.version', null, '2.0.0')));
