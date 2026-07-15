<?php

/**
 * Уборка тестовых сущностей API-смоука (пользователи, токены, тип/проект «smoke_*»).
 * Запуск на стенде: /usr/local/php/php-8.3/bin/php _api_teardown_remote.php
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modUserGroupRole;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;
use MxBoard\Model\MxBoardToken;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbapiteardown');
$modx->initialize('mgr');

$corePath = MODX_CORE_PATH . 'components/mxboard/';
if (is_file($corePath . 'vendor/autoload.php')) {
    require_once $corePath . 'vendor/autoload.php';
}
if (!isset($modx->packages['MxBoard\\Model'])) {
    $modx->addPackage('MxBoard\\Model', $corePath . 'src/', null, 'MxBoard\\');
}

foreach (['mxb_api_worker', 'mxb_api_mgr'] as $username) {
    /** @var modUser|null $user */
    $user = $modx->getObject(modUser::class, ['username' => $username]);
    if (!$user) {
        continue;
    }
    $uid = (int) $user->get('id');
    foreach ($modx->getCollection(modUserGroupMember::class, ['member' => $uid]) as $m) {
        $m->remove();
    }
    foreach ($modx->getCollection(MxBoardToken::class, ['user_id' => $uid]) as $t) {
        $t->remove();
    }
    foreach ($modx->getCollection(MxBoardTask::class, ['author_id' => $uid]) as $task) {
        $task->remove();
    }
    $user->remove();
    echo "removed user {$username}\n";
}

// Тип smoke_type + его поля.
if ($type = $modx->getObject(MxBoardTaskType::class, ['key' => 'smoke_type'])) {
    foreach ($modx->getCollection(MxBoardField::class, ['task_type_id' => $type->get('id')]) as $f) {
        $f->remove();
    }
    $type->remove();
    echo "removed type smoke_type\n";
}

// Проект smoke_proj + его колонки.
if ($proj = $modx->getObject(MxBoardProject::class, ['key' => 'smoke_proj'])) {
    foreach ($modx->getCollection(MxBoardColumn::class, ['project_id' => $proj->get('id')]) as $c) {
        $c->remove();
    }
    $proj->remove();
    echo "removed project smoke_proj\n";
}

// Тестовая роль.
if ($role = $modx->getObject(modUserGroupRole::class, ['name' => 'mxb-smoke-admin'])) {
    $role->remove();
    echo "removed role mxb-smoke-admin\n";
}

// Возврат порога менеджера в 0 + сброс кэша настроек.
if ($setting = $modx->getObject(modSystemSetting::class, 'mxboard.group_admin_authority')) {
    $setting->set('value', '0');
    $setting->save();
}
$modx->getCacheManager()->refresh(['system_settings' => []]);
echo "group_admin_authority reset to 0\n";

echo "TEARDOWN DONE\n";
