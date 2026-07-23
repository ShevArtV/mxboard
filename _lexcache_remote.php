<?php

/**
 * Точечный сброс кэша на стенде после заливки лексикона и собранного Vue-бандла.
 * Кладётся в корень стенда рядом с config.core.php, дёргается по HTTP —
 * только так сброс попадает в тот же процесс PHP-FPM, чей OPcache и кэширует
 * lexicon_topics (CLI-сброс до FPM не доезжает).
 */

use MODX\Revolution\modX;

define('MODX_API_MODE', true);

require_once __DIR__ . '/config.core.php';
require_once MODX_CORE_PATH . 'vendor/autoload.php';

$modx = modX::getInstance('mxblex');
$modx->initialize('mgr');

$modx->getCacheManager()->refresh([
    'lexicon_topics' => [],
    'system_settings' => [],
]);

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "opcache reset\n";
} else {
    echo "no opcache\n";
}

echo "lexicon_topics refreshed\n";
