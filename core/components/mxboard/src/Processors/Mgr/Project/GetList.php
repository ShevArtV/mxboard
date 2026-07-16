<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Project;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Активные проекты для селектора доски (видишь любой; пуста доска там, где не участвуешь).
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->success('', (new BoardQuery($this->modx))->projects());
    }
}
