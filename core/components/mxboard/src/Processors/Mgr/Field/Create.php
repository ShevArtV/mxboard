<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Field;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\StructureService;

/**
 * Добавление поля к типу — менеджер отдела типа.
 */
class Create extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $data = $this->presentProperties(['task_type_id', 'key', 'label', 'type', 'required', 'position', 'options']);

        $options = $this->jsonProperty('options');
        if ($options !== null) {
            $data['options'] = $options;
        }

        return $this->fromResult((new StructureService($this->modx))->createField($user, $data));
    }
}
