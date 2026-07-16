<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Department;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Реестр отделов для селектора доски. Имена не секретны — скоуп по видимости
 * применяется на карточках, не на списках.
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->success('', (new BoardQuery($this->modx))->departments());
    }
}
