<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Department;

use MODX\Revolution\modUserGroup;
use MODX\Revolution\modUser;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Processors\Mgr\ServiceProcessor;

/**
 * Группы пользователей MODX — кандидаты в отделы (экран «Структура», регистрация отдела).
 *
 * Только менеджеру (супер хотя бы одного отдела или sudo): список групп — служебная
 * информация настройки, рядовому исполнителю она не нужна. У каждой группы помечаем,
 * зарегистрирована ли она уже как отдел (registered), чтобы UI не предлагал дубль.
 */
class Groups extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        if (!Transitions::isAnyDepartmentManager($this->modx, $user)) {
            return $this->failure($this->modx->lexicon('mxboard_err_structure_denied'));
        }

        // id зарегистрированных отделов-групп — одним запросом, чтобы не бить по одной.
        $registered = [];
        foreach ($this->modx->getCollection(MxBoardDepartment::class) as $dept) {
            $registered[(int) $dept->get('usergroup_id')] = true;
        }

        $c = $this->modx->newQuery(modUserGroup::class);
        $c->sortby('name', 'ASC');

        $out = [];
        /** @var modUserGroup $group */
        foreach ($this->modx->getCollection(modUserGroup::class, $c) as $group) {
            $gid = (int) $group->get('id');
            $out[] = [
                'id' => $gid,
                'name' => (string) $group->get('name'),
                'registered' => isset($registered[$gid]),
            ];
        }

        return $this->success('', $out);
    }
}
