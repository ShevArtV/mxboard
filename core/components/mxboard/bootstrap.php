<?php

/**
 * @var \MODX\Revolution\modX $modx
 * @var array $namespace
 */

$autoload = $namespace['path'] . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

if (is_dir($namespace['path'] . 'src/Model')) {
    $modx->addPackage('MxBoard\Model', $namespace['path'] . 'src/', null, 'MxBoard\\');
}

$modx->services->add('mxBoard', function ($c) use ($modx) {
    return new MxBoard\MxBoard($modx);
});
