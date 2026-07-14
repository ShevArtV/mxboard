<?php

/**
 * Headless-установка transport-пакета mxBoard на MODX 3 стенде.
 * Кладётся в корень стенда (рядом с config.core.php), запускается php-8.x:
 *   /usr/local/php/php-8.3/bin/php _install_remote.php mxboard-1.0.0-alpha
 * Если пакет уже стоит — переустанавливает начисто (uninstall + remove → install).
 */

use MODX\Revolution\modX;
use MODX\Revolution\Transport\modTransportPackage;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxbinstall');
$modx->initialize('mgr');
$modx->getService('lexicon', 'modLexicon');
$modx->setLogLevel(modX::LOG_LEVEL_INFO);
$modx->setLogTarget('ECHO');

$signature = $argv[1] ?? 'mxboard-1.0.0-alpha';
if (!preg_match('/^(.+)-(\d+\.\d+\.\d+)(?:-(.+))?$/', $signature, $m)) {
    echo "BAD SIGNATURE: {$signature}\n";
    exit(1);
}

$file = MODX_CORE_PATH . 'packages/' . $signature . '.transport.zip';
if (!file_exists($file)) {
    echo "ZIP NOT FOUND: {$file}\n";
    exit(1);
}

$existing = $modx->getObject(modTransportPackage::class, ['signature' => $signature]);
if ($existing) {
    echo "Пакет уже установлен — переустанавливаю начисто...\n";
    @$existing->uninstall();
    $existing->remove();
}

$versionParts = explode('.', $m[2]);
$release = $m[3] ?? '';

/** @var modTransportPackage $package */
$package = $modx->newObject(modTransportPackage::class);
$package->set('signature', $signature);
$package->fromArray([
    'created' => date('Y-m-d H:i:s'),
    'updated' => null,
    'state' => 1,
    'workspace' => 1,
    'provider' => 0,
    'source' => $signature . '.transport.zip',
    'package_name' => $m[1],
    'version_major' => $versionParts[0] ?? 0,
    'version_minor' => $versionParts[1] ?? 0,
    'version_patch' => $versionParts[2] ?? 0,
]);
if ($release !== '') {
    $r = preg_split('#([0-9]+)#', $release, -1, PREG_SPLIT_DELIM_CAPTURE);
    $package->set('release', $r[0] ?? $release);
    $package->set('release_index', $r[1] ?? '0');
}
$package->save();

echo "Устанавливаю {$signature}...\n";
if ($package->install()) {
    $modx->runProcessor('System/ClearCache');
    echo "INSTALLED OK\n";
} else {
    echo "INSTALL FAILED\n";
    exit(1);
}
