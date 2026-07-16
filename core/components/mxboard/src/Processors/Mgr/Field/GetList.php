<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Field;

use MODX\Revolution\modUser;
use MxBoard\Model\MxBoardField;
use MxBoard\Processors\Mgr\ServiceProcessor;

/**
 * Поля типа с id — для редактора полей (вкладка «Типы» экрана «Структура»).
 * Поля не секретны (Type/Schema и так отдаёт их без id) — доступно всем авторизованным.
 */
class GetList extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $typeId = (int) $this->getProperty('task_type_id', 0);
        if ($typeId <= 0) {
            return $this->failure($this->modx->lexicon('mxboard_err_type_not_found'));
        }

        $c = $this->modx->newQuery(MxBoardField::class);
        $c->where(['task_type_id' => $typeId]);
        $c->sortby('position', 'ASC');
        $c->sortby('id', 'ASC');

        $out = [];
        /** @var MxBoardField $field */
        foreach ($this->modx->getCollection(MxBoardField::class, $c) as $field) {
            $out[] = [
                'id' => (int) $field->get('id'),
                'key' => (string) $field->get('key'),
                'label' => (string) $field->get('label'),
                'type' => (string) $field->get('type'),
                'required' => (bool) $field->get('required'),
                'position' => (int) $field->get('position'),
                'options' => $field->get('options'),
            ];
        }

        return $this->success('', $out);
    }
}
