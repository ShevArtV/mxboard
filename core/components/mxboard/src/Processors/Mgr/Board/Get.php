<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Board;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Processor;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\TaskService;
use PDO;

/**
 * Доска целиком — один запрос вместо N-запросов из UI.
 *
 * Отдаём готовую структуру для отрисовки: доска → колонки (по position) → карточки
 * (по position). Карточки, счётчики комментариев и имена пользователей собираются
 * тремя запросами и склеиваются в PHP, а не циклом getObject на каждую карточку.
 */
class Get extends Processor
{
    public $languageTopics = ['mxboard:default'];

    public function process()
    {
        // Через коннектор топик не всегда успевает подгрузиться до process(),
        // и lexicon() вернул бы голый ключ.
        $this->modx->lexicon->load('mxboard:default');

        $key = trim((string) $this->getProperty('board', ''));

        $service = new TaskService($this->modx);
        $board = $service->resolveBoard($key !== '' ? $key : null);
        if (!$board) {
            return $this->failure($this->modx->lexicon('mxboard_err_board_not_found'));
        }

        $boardId = (int) $board->get('id');

        $columns = $this->columns($boardId);
        $counts = $this->commentCounts($boardId);

        foreach ($this->tasks($boardId) as $row) {
            $columnId = (int) $row['column_id'];
            if (!isset($columns[$columnId])) {
                // Осиротевшая карточка (колонку удалили) — на доске рисовать негде.
                continue;
            }

            $taskId = (int) $row['id'];
            $assignee = (string) ($row['assignee'] ?? '');

            $columns[$columnId]['tasks'][] = [
                'id' => $taskId,
                'column_id' => $columnId,
                'title' => (string) $row['title'],
                'priority' => (int) $row['priority'],
                // Идентификаторы нужны интерфейсу не для показа, а для решений: по author_id
                // доска понимает, вправе ли текущий пользователь тянуть карточку в done,
                // по assignee_id — свободна она или уже взята. Одних имён недостаточно.
                'author_id' => (int) $row['author_id'],
                'assignee_id' => (int) $row['assignee_id'],
                'author' => (string) ($row['author'] ?? ''),
                'assignee' => $assignee !== '' ? $assignee : null,
                'createdon' => (int) $row['createdon'],
                'createdon_formatted' => $row['createdon'] ? date('Y-m-d H:i', (int) $row['createdon']) : '',
                'comments' => $counts[$taskId] ?? 0,
            ];
        }

        return $this->success('', [
            'board' => $board->toArray(),
            'columns' => array_values($columns),
        ]);
    }

    /**
     * Колонки доски по position, ключ массива — id колонки (для быстрой раскладки карточек).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function columns(int $boardId): array
    {
        $c = $this->modx->newQuery(MxBoardColumn::class);
        $c->where(['board_id' => $boardId]);
        $c->sortby('position', 'ASC');
        $c->sortby('id', 'ASC');

        $columns = [];
        /** @var MxBoardColumn $column */
        foreach ($this->modx->getIterator(MxBoardColumn::class, $c) as $column) {
            $array = $column->toArray();
            $array['tasks'] = [];
            $columns[(int) $column->get('id')] = $array;
        }

        return $columns;
    }

    /**
     * Карточки доски с именами автора и исполнителя, по position.
     *
     * @return list<array<string, mixed>>
     */
    protected function tasks(int $boardId): array
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->leftJoin(modUser::class, 'Author');
        $c->leftJoin(modUser::class, 'Assignee');
        $c->where(['MxBoardTask.board_id' => $boardId]);
        // author_id и assignee_id нужны интерфейсу не для показа, а для решений:
        // по author_id доска понимает, вправе ли текущий пользователь тянуть карточку
        // в done, по assignee_id — свободна карточка или уже взята. Без них интерфейс
        // считает автором никого и запрещает закрытие даже автору.
        $c->select($this->modx->getSelectColumns(
            MxBoardTask::class,
            'MxBoardTask',
            '',
            ['id', 'column_id', 'title', 'priority', 'author_id', 'assignee_id', 'createdon']
        ));
        $c->select(['Author.username AS author', 'Assignee.username AS assignee']);
        $c->sortby('MxBoardTask.position', 'ASC');
        $c->sortby('MxBoardTask.id', 'ASC');

        if (!$c->prepare() || !$c->stmt->execute()) {
            return [];
        }

        return $c->stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Число комментариев по каждой карточке доски: одним GROUP BY, а не запросом на карточку.
     *
     * @return array<int, int>
     */
    protected function commentCounts(int $boardId): array
    {
        $c = $this->modx->newQuery(MxBoardComment::class);
        $c->innerJoin(MxBoardTask::class, 'Task');
        $c->where(['Task.board_id' => $boardId]);
        $c->select(['MxBoardComment.task_id', 'COUNT(MxBoardComment.id) AS total']);
        $c->groupby('MxBoardComment.task_id');

        if (!$c->prepare() || !$c->stmt->execute()) {
            return [];
        }

        $counts = [];
        foreach ($c->stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['task_id']] = (int) $row['total'];
        }

        return $counts;
    }
}
