<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Column;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Удаление колонки. Носитель initial/final и непустая колонка не удаляются — правила в сервисе.
 */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->removeColumn(
            $user,
            (int) $this->getProperty('id', 0)
        ));
    }
}
