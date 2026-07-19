<?php

/**
 * Resolver: политика доступа «mxBoard Only» — пресет ограниченного менеджера.
 *
 * Whitelist-подход: берём ядровый шаблон AdministratorTemplate (полный набор
 * менеджерских пермишенов), гасим ВСЁ и включаем только необходимый минимум для
 * работы с канбаном. Всё, что не в whitelist, закрыто автоматически — и не разъедется
 * на будущих версиях MODX (шаблон приносит актуальный набор прав сам).
 *
 * Минимум определён эмпирически (браузер-проверка на стенде MODX 3.2.1):
 *   frames  — вход в менеджер (без него «Доступ запрещён» ещё на логине);
 *   load    — connectors/index.php делает context->checkPolicy('load'); без него
 *             коннектор mxBoard отдаёт 401 и справочники/доска не грузятся;
 *   list,view — чтение списков/объектов процессорами;
 *   home    — стартовая страница до киоск-редиректа;
 *   components — пункт меню «Пакеты/Компоненты» → mxBoard;
 *   logout  — выход.
 *
 * Идемпотентен: политику создаёт один раз, при upgrade не перезаписывает (администратор
 * мог доработать набор прав под себя). НИКОМУ не назначает — назначение группе-отделу
 * через «Доступ к контексту mgr» остаётся ручным (см. README, раздел «Киоск-доступ»).
 *
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */

use MODX\Revolution\modX;
use MODX\Revolution\modAccessPolicy;
use MODX\Revolution\modAccessPolicyTemplate;
use MODX\Revolution\modAccessPermission;
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

$POLICY_NAME = 'mxBoard Only';

$whitelist = [
    'frames',
    'logout',
    'home',
    'components',
    'load',
    'list',
    'view',
];

// Уже есть (upgrade/переустановка) — не трогаем набор прав администратора.
if ($modx->getObject(modAccessPolicy::class, ['name' => $POLICY_NAME])) {
    $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Политика «mxBoard Only» уже существует — пропускаю.');
    return true;
}

/** @var modAccessPolicyTemplate|null $tpl */
$tpl = $modx->getObject(modAccessPolicyTemplate::class, ['name' => 'AdministratorTemplate']);
if (!$tpl) {
    $modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard] Нет шаблона AdministratorTemplate — политика «mxBoard Only» не создана.');
    return true;
}

// Полный набор пермишенов шаблона = false, затем включаем whitelist.
$data = [];
foreach ($modx->getCollection(modAccessPermission::class, ['template' => $tpl->get('id')]) as $perm) {
    $data[$perm->get('name')] = false;
}
foreach ($whitelist as $name) {
    if (!array_key_exists($name, $data)) {
        $modx->log(modX::LOG_LEVEL_WARN, "[mxBoard] Пермишен «{$name}» отсутствует в AdministratorTemplate — пропущен.");
        continue;
    }
    $data[$name] = true;
}

/** @var modAccessPolicy $policy */
$policy = $modx->newObject(modAccessPolicy::class);
$policy->fromArray([
    'name' => $POLICY_NAME,
    'description' => 'Ограниченный доступ в менеджер: только канбан mxBoard. '
        . 'Назначьте группе-отделу через «Доступ к контексту mgr».',
    'template' => $tpl->get('id'),
    'data' => $data,
]);
if ($policy->save()) {
    $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Политика «mxBoard Only» создана (whitelist: ' . implode(', ', $whitelist) . ').');
} else {
    $modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard] Не удалось сохранить политику «mxBoard Only».');
}

return true;
