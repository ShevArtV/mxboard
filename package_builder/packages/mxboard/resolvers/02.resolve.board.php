<?php

/**
 * Resolver: доска по умолчанию с пятью колонками — чтобы после установки было
 * куда класть задачи, без ручной настройки.
 *
 * Идемпотентен: доска с ключом default создаётся один раз, при upgrade не трогается
 * (пользователь мог переименовать колонки или поменять права переходов).
 *
 * Модель прав переходов (move_roles) — CSV ролей относительно карточки:
 *   author   — тот, кто поставил задачу
 *   assignee — тот, кто её взял
 *   any      — любой пользователь с доступом к доске
 * Плюс глобальное право mxboard_move_any (обходит правила колонки).
 *
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */

use MODX\Revolution\modX;
use xPDO\Transport\xPDOTransport;

if (!$transport->xpdo) {
    return true;
}

/** @var modX $modx */
$modx = $transport->xpdo;
$action = $options[xPDOTransport::PACKAGE_ACTION] ?? '';

if ($action === xPDOTransport::ACTION_UNINSTALL) {
    return true;
}

$corePath = $modx->getOption('core_path') . 'components/mxboard/';

$autoload = $corePath . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

$boardKey = 'default';

/** @var \MxBoard\Model\MxBoardBoard|null $board */
$board = $modx->getObject(\MxBoard\Model\MxBoardBoard::class, ['key' => $boardKey]);
if ($board) {
    $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Доска default уже есть — не трогаем.');
    return true;
}

$now = time();

$board = $modx->newObject(\MxBoard\Model\MxBoardBoard::class);
$board->fromArray([
    'key' => $boardKey,
    'name' => 'Основная доска',
    'description' => 'Доска по умолчанию, создана при установке mxBoard.',
    'active' => true,
    'createdon' => $now,
    'updatedon' => $now,
]);

if (!$board->save()) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard] Не удалось создать доску default.');
    return true;
}

$boardId = (int) $board->get('id');

$columns = [
    // Постановка задач: кладёт автор (или менеджер с правом move_any).
    ['key' => 'backlog', 'name' => 'Бэклог', 'position' => 0, 'move_roles' => 'author', 'is_initial' => true],
    // Готово к работе: отсюда исполнитель забирает карточку захватом.
    ['key' => 'ready', 'name' => 'Готово к работе', 'position' => 1, 'move_roles' => 'author', 'is_ready' => true],
    // В работе: двигает тот, кто взял.
    ['key' => 'in_progress', 'name' => 'В работе', 'position' => 2, 'move_roles' => 'assignee'],
    // На проверке: потолок исполнителя — дальше только автор.
    ['key' => 'review', 'name' => 'На проверке', 'position' => 3, 'move_roles' => 'assignee,author'],
    // Готово: закрывает ТОЛЬКО автор задачи. Исполнитель сюда не дотянется.
    ['key' => 'done', 'name' => 'Готово', 'position' => 4, 'move_roles' => 'author', 'is_final' => true],
];

foreach ($columns as $data) {
    $column = $modx->newObject(\MxBoard\Model\MxBoardColumn::class);
    $column->fromArray(array_merge([
        'board_id' => $boardId,
        'is_initial' => false,
        'is_ready' => false,
        'is_final' => false,
        'createdon' => $now,
    ], $data));
    $column->save();
}

$modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Доска default создана: 5 колонок.');

return true;
