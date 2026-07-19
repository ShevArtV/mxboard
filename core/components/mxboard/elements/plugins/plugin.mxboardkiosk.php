<?php

/**
 * mxBoardKiosk — «киоск-режим» менеджера для рядовых членов отдела.
 *
 * На OnManagerPageInit: если пользователь НЕ sudo и состоит хотя бы в одной группе
 * из настройки mxboard.kiosk_usergroups (CSV имён групп), любую страницу менеджера,
 * кроме самой доски и выхода, редиректим на канбан ?a=board&namespace=mxboard.
 *
 * Так член отдела с урезанной политикой доступа «mxBoard Only» (см. resolver
 * 04.resolve.policy.php) после входа сразу попадает на доску и не видит дашборд.
 * Ограничение ПРАВ — задача политики доступа; этот плагин лишь про стартовую точку.
 *
 * По умолчанию настройка пуста → плагин не трогает никого (opt-in).
 *
 * @var \MODX\Revolution\modX $modx
 * @var array $scriptProperties
 */

use MODX\Revolution\modX;

if ($modx->event->name !== 'OnManagerPageInit') {
    return;
}

$user = $modx->user;
if (!$user || (bool) $user->get('sudo')) {
    return; // суперпользователя не запираем
}

$raw = (string) $modx->getOption('mxboard.kiosk_usergroups', null, '');
$targetGroups = array_filter(array_map('trim', explode(',', $raw)));
if (empty($targetGroups)) {
    return; // фича выключена
}

$userGroups = $user->getUserGroupNames();
if (empty(array_intersect($targetGroups, $userGroups))) {
    return; // пользователь не из «киоск»-групп
}

$action = (string) ($scriptProperties['action'] ?? ($_REQUEST['a'] ?? ''));
$namespace = (string) ($scriptProperties['namespace'] ?? ($_REQUEST['namespace'] ?? ''));

// Уже на доске — не трогаем. Выход (security/logout и т.п.) — не мешаем.
if (($namespace === 'mxboard' && $action === 'board') || strpos($action, 'security/') === 0) {
    return;
}

$modx->sendRedirect(MODX_MANAGER_URL . '?a=board&namespace=mxboard');
