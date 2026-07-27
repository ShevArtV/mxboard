<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Project;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\BoardQuery;

/**
 * Активные проекты для селектора доски (видишь любой; пуста доска там, где не участвуешь).
 *
 * С флагом `creatable` список сужается до проектов, где пользователь вправе заводить
 * карточки (#2607-132): его берёт селектор проекта в диалоге подзадачи, чтобы не
 * предлагать вариант, на котором сервер откажет. Смотреть доску чужого отдела при этом
 * по-прежнему можно — ограничение только на создание.
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $creatable = !empty($this->getProperty('creatable'));
        $query = new BoardQuery($this->modx);

        return $this->success('', $creatable ? $query->creatableProjects($user) : $query->projects());
    }
}
