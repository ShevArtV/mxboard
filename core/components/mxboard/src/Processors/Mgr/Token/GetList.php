<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Token;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Model\GetListProcessor;
use MxBoard\Model\MxBoardToken;
use xPDO\Om\xPDOObject;
use xPDO\Om\xPDOQuery;

/**
 * Список токенов агентов. Хэш наружу не отдаём никогда: сам токен виден
 * ровно один раз — в ответе Token/Create.
 */
class GetList extends GetListProcessor
{
    public $classKey = MxBoardToken::class;
    public $languageTopics = ['mxboard:default'];
    public $defaultSortField = 'createdon';
    public $defaultSortDirection = 'DESC';
    public $checkListPermission = false;

    /** Реальные колонки таблицы — только по ним можно сортировать. */
    protected array $sortable = ['id', 'user_id', 'name', 'active', 'lastusedon', 'createdon'];

    public function initialize()
    {
        $result = parent::initialize();

        // username — вычисляемая колонка из JOIN, сортировка по ней сломала бы SQL.
        if (!in_array((string) $this->getProperty('sort'), $this->sortable, true)) {
            $this->setProperty('sort', $this->defaultSortField);
        }

        return $result;
    }

    public function prepareQueryBeforeCount(xPDOQuery $c)
    {
        $c->leftJoin(modUser::class, 'User');

        $userId = $this->getProperty('user_id');
        if ($userId !== null && $userId !== '') {
            $c->where(['MxBoardToken.user_id' => (int) $userId]);
        }

        $active = $this->getProperty('active');
        if ($active !== null && $active !== '') {
            $c->where(['MxBoardToken.active' => (bool) $active]);
        }

        $query = trim((string) $this->getProperty('query', ''));
        if ($query !== '') {
            $c->where([
                'MxBoardToken.name:LIKE' => '%' . $query . '%',
                'OR:User.username:LIKE' => '%' . $query . '%',
            ]);
        }

        return $c;
    }

    public function prepareQueryAfterCount(xPDOQuery $c)
    {
        $c->select($this->modx->getSelectColumns(
            MxBoardToken::class,
            'MxBoardToken',
            '',
            ['token_hash'],
            true // exclude: хэш из выборки исключён на уровне SQL, а не «забыт» в prepareRow
        ));
        $c->select(['User.username AS username']);

        return $c;
    }

    public function prepareRow(xPDOObject $object)
    {
        $array = $object->toArray();

        unset($array['token_hash']);

        $array['username'] = (string) ($array['username'] ?? '');
        $array['createdon_formatted'] = !empty($array['createdon'])
            ? date('Y-m-d H:i:s', (int) $array['createdon'])
            : '';
        $array['lastusedon_formatted'] = !empty($array['lastusedon'])
            ? date('Y-m-d H:i:s', (int) $array['lastusedon'])
            : '';

        return $array;
    }
}
