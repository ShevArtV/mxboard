<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Model\GetListProcessor;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\TaskService;
use xPDO\Om\xPDOObject;
use xPDO\Om\xPDOQuery;

/**
 * Список карточек для грида. Фильтры: board (ключ доски), column (ключ колонки),
 * assignee, author (id пользователей), query (подстрока в заголовке).
 */
class GetList extends GetListProcessor
{
    public $classKey = MxBoardTask::class;
    public $languageTopics = ['mxboard:default'];
    public $defaultSortField = 'createdon';
    public $defaultSortDirection = 'DESC';
    public $checkListPermission = false;

    /** Реальные колонки таблицы — только по ним можно сортировать. */
    protected array $sortable = [
        'id', 'board_id', 'column_id', 'title', 'author_id', 'assignee_id',
        'rank', 'priority', 'createdon', 'updatedon', 'startedon', 'closedon',
    ];

    public function initialize()
    {
        $result = parent::initialize();

        // Защита от сортировки по вычисляемым колонкам (author, assignee, column_key) —
        // их нет в таблице, и sortby по ним даст SQL-ошибку.
        if (!in_array((string) $this->getProperty('sort'), $this->sortable, true)) {
            $this->setProperty('sort', $this->defaultSortField);
        }

        return $result;
    }

    public function prepareQueryBeforeCount(xPDOQuery $c)
    {
        $c->innerJoin(MxBoardColumn::class, 'Column');
        $c->leftJoin(modUser::class, 'Author');
        $c->leftJoin(modUser::class, 'Assignee');

        $boardKey = trim((string) $this->getProperty('board', ''));
        if ($boardKey !== '') {
            $board = (new TaskService($this->modx))->resolveBoard($boardKey);
            // Несуществующая/выключенная доска = пустая выдача, а не «все карточки всех досок».
            $c->where(['MxBoardTask.board_id' => $board ? (int) $board->get('id') : 0]);
        }

        $columnKey = trim((string) $this->getProperty('column', ''));
        if ($columnKey !== '') {
            $c->where(['Column.key' => $columnKey]);
        }

        $assignee = $this->getProperty('assignee');
        if ($assignee !== null && $assignee !== '') {
            $c->where(['MxBoardTask.assignee_id' => (int) $assignee]);
        }

        $author = $this->getProperty('author');
        if ($author !== null && $author !== '') {
            $c->where(['MxBoardTask.author_id' => (int) $author]);
        }

        $query = trim((string) $this->getProperty('query', ''));
        if ($query !== '') {
            $c->where(['MxBoardTask.title:LIKE' => '%' . $query . '%']);
        }

        return $c;
    }

    public function prepareQueryAfterCount(xPDOQuery $c)
    {
        // Селекты только здесь: в счётном запросе они не нужны, а лишний GROUP BY-мусор
        // в COUNT только мешает.
        $c->select($this->modx->getSelectColumns(MxBoardTask::class, 'MxBoardTask'));
        $c->select([
            'Column.key AS column_key',
            'Column.name AS column_name',
            'Author.username AS author',
            'Assignee.username AS assignee',
        ]);

        return $c;
    }

    public function prepareRow(xPDOObject $object)
    {
        $array = $object->toArray();

        $array['createdon_formatted'] = $array['createdon']
            ? date('Y-m-d H:i:s', (int) $array['createdon'])
            : '';
        $array['updatedon_formatted'] = $array['updatedon']
            ? date('Y-m-d H:i:s', (int) $array['updatedon'])
            : '';

        $array['assignee'] = !empty($array['assignee']) ? (string) $array['assignee'] : null;
        $array['is_free'] = (int) $object->get('assignee_id') === 0;

        // ToR бывает на килобайты — в грид-строке он не нужен, читается в окне детали (Task/Get).
        unset($array['tor']);

        return $array;
    }
}
