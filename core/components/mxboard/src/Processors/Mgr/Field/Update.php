<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Field;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Правка поля типа (label, type, required, position, options; key неизменен —
 * по нему адресуются значения в task.fields).
 */
class Update extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $data = $this->presentProperties(['label', 'type', 'required', 'position', 'options']);

        if ($this->getProperty('options') !== null) {
            $data['options'] = $this->jsonProperty('options');
        }

        return $this->fromResult((new StructureService($this->modx))->updateField(
            $user,
            (int) $this->getProperty('id', 0),
            $data
        ));
    }
}
