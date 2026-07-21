<?php

declare(strict_types=1);

namespace MxBoard;

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardProject;

/**
 * Сервис-класс компонента. Регистрируется в контейнере как `mxBoard` (bootstrap.php).
 */
class MxBoard
{
    public const VERSION = '2.0.0';

    public modX $modx;

    /** @var array<string, mixed> */
    public array $config = [];

    public function __construct(modX $modx)
    {
        $this->modx = $modx;

        $corePath = $modx->getOption('mxboard.core_path', null, $modx->getOption('core_path') . 'components/mxboard/');
        $assetsUrl = $modx->getOption('mxboard.assets_url', null, $modx->getOption('assets_url') . 'components/mxboard/');

        $this->config = [
            'corePath' => $corePath,
            'assetsUrl' => $assetsUrl,
            'defaultProject' => (string) $modx->getOption('mxboard.default_project', null, 'default'),
            'allowSelfClose' => (bool) $modx->getOption('mxboard.allow_self_close', null, false),
            'wipLimit' => (int) $modx->getOption('mxboard.wip_limit', null, 0),
            'groupAdminAuthority' => (int) $modx->getOption('mxboard.group_admin_authority', null, 1),
        ];
    }

    /**
     * Проект по ключу; без ключа — проект по умолчанию из системных настроек.
     */
    public function getProject(?string $key = null): ?MxBoardProject
    {
        $key = $key ?: $this->config['defaultProject'];

        /** @var MxBoardProject|null $project */
        $project = $this->modx->getObject(MxBoardProject::class, ['key' => $key, 'active' => true]);

        return $project;
    }
}
