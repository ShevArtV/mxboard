<?php

/**
 * Resolver: выделенный media source для вложений mxBoard.
 *
 * Философия пакета — «ставится на любой MODX 3 из коробки»: файлы задач НЕ должны
 * сыпаться в общий сайтовый источник. Поэтому при установке заводим отдельный
 * file-источник «mxBoard» с корнем в assets/mxboard-uploads/ и прописываем его id
 * в настройку mxboard.media_source.
 *
 * Идемпотентно: если mxboard.media_source уже указывает на живой источник — не трогаем
 * (пользователь мог настроить свой). Создание обёрнуто в try/catch: если по какой-то
 * причине не выйдет — оставляем настройку как есть (0 = дефолтный источник рантаймом,
 * AttachmentService всё равно отработает через getDefaultSource fallback), только логируем.
 *
 * @var \xPDO\Transport\xPDOTransport $transport
 * @var array $options
 */

use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modX;
use MODX\Revolution\Sources\modMediaSource;
use MODX\Revolution\Sources\modFileMediaSource;
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

$SOURCE_NAME = 'mxBoard';
$UPLOAD_DIR = 'assets/mxboard-uploads/';

try {
    // Уже настроен живой источник — уважаем выбор пользователя.
    $currentId = (int) $modx->getOption('mxboard.media_source', null, 0);
    if ($currentId > 0 && $modx->getObject(modMediaSource::class, $currentId)) {
        $modx->log(modX::LOG_LEVEL_INFO, "[mxBoard] media source уже настроен (id={$currentId}).");
        return true;
    }

    // Источник мог остаться от прошлой установки — переиспользуем по имени.
    /** @var modMediaSource|null $source */
    $source = $modx->getObject(modMediaSource::class, ['name' => $SOURCE_NAME]);

    if (!$source) {
        // Физическая папка корня. Local-адаптер flysystem создаёт подпапки при записи,
        // но корень заводим сами — чтобы браузер файлов сразу видел валидный источник.
        $absDir = rtrim(MODX_BASE_PATH, '/') . '/' . $UPLOAD_DIR;
        if (!is_dir($absDir)) {
            @mkdir($absDir, 0755, true);
        }

        /** @var modMediaSource $source */
        $source = $modx->newObject(modMediaSource::class);
        $source->fromArray([
            'name' => $SOURCE_NAME,
            'description' => 'Вложения задач mxBoard (файлы к задачам и сообщениям чата).',
            'class_key' => modFileMediaSource::class,
        ], '', true, true);

        // Свойства перекрывают только basePath/baseUrl — остальное наследуется из
        // getDefaultProperties() источника (getProperties мержит по имени).
        $source->set('properties', [
            'basePath' => ['name' => 'basePath', 'type' => 'textfield', 'value' => $UPLOAD_DIR],
            'basePathRelative' => ['name' => 'basePathRelative', 'type' => 'combo-boolean', 'value' => true],
            'baseUrl' => ['name' => 'baseUrl', 'type' => 'textfield', 'value' => $UPLOAD_DIR],
            'baseUrlRelative' => ['name' => 'baseUrlRelative', 'type' => 'combo-boolean', 'value' => true],
        ]);

        if (!$source->save()) {
            $modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] Не удалось создать media source — mxboard.media_source оставлен как есть.');
            return true;
        }
        $modx->log(modX::LOG_LEVEL_INFO, '[mxBoard] Создан media source «' . $SOURCE_NAME . '» (id=' . (int) $source->get('id') . ').');
    }

    // Прописываем id в настройку.
    $sourceId = (int) $source->get('id');
    /** @var modSystemSetting|null $setting */
    $setting = $modx->getObject(modSystemSetting::class, 'mxboard.media_source');
    if (!$setting) {
        $setting = $modx->newObject(modSystemSetting::class);
        $setting->fromArray([
            'key' => 'mxboard.media_source',
            'xtype' => 'numberfield',
            'namespace' => 'mxboard',
            'area' => 'mxboard_main',
        ], '', true, true);
    }
    $setting->set('value', (string) $sourceId);
    $setting->save();

    $modx->log(modX::LOG_LEVEL_INFO, "[mxBoard] mxboard.media_source = {$sourceId}.");
} catch (\Throwable $e) {
    // Резолвер не должен ронять установку пакета из-за media source.
    $modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] Резолвер media source: ' . $e->getMessage());
}

return true;
