<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Type;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Удаление типа без задач (поля уходят каскадом) — менеджер отдела. Правило в сервисе.
 */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->removeType(
            $user,
            (int) $this->getProperty('id', 0)
        ));
    }
}
