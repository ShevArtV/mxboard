<?php

declare(strict_types=1);

namespace MxBoard;

use MODX\Revolution\modX;
use MxBoard\Model\MxBoardBoard;

/**
 * Сервис-класс компонента. Регистрируется в контейнере как `mxBoard` (bootstrap.php).
 */
class MxBoard
{
    public const VERSION = '1.0.0';

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
            'defaultBoard' => (string) $modx->getOption('mxboard.default_board', null, 'default'),
            'allowSelfClose' => (bool) $modx->getOption('mxboard.allow_self_close', null, false),
            'wipLimit' => (int) $modx->getOption('mxboard.wip_limit', null, 0),
        ];
    }

    /**
     * Доска по ключу; без ключа — доска по умолчанию из системных настроек.
     */
    public function getBoard(?string $key = null): ?MxBoardBoard
    {
        $key = $key ?: $this->config['defaultBoard'];

        /** @var MxBoardBoard|null $board */
        $board = $this->modx->getObject(MxBoardBoard::class, ['key' => $key, 'active' => true]);

        return $board;
    }
}
