<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Field;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Удаление поля типа. Последнее поле не удаляется (тип станет нерабочим) — правило в сервисе.
 */
class Remove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new StructureService($this->modx))->removeField(
            $user,
            (int) $this->getProperty('id', 0)
        ));
    }
}
