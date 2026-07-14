<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Model\GetProcessor;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardTask;
use PDO;

/**
 * Карточка целиком: поля + ToR + комментарии + журнал переходов.
 * Журнал показываем всегда — по нему видно, кто что реально делал, в отличие
 * от текущего статуса, который агент может себе выставить сам.
 */
class Get extends GetProcessor
{
    public $classKey = MxBoardTask::class;
    public $languageTopics = ['mxboard:default'];
    public $objectType = 'mxboard.task';
    public $checkViewPermission = false;

    public function cleanup()
    {
        $array = $this->object->toArray();
        $taskId = (int) $this->object->get('id');

        $array['createdon_formatted'] = $array['createdon'] ? date('Y-m-d H:i:s', (int) $array['createdon']) : '';
        $array['updatedon_formatted'] = $array['updatedon'] ? date('Y-m-d H:i:s', (int) $array['updatedon']) : '';
        $array['startedon_formatted'] = $array['startedon'] ? date('Y-m-d H:i:s', (int) $array['startedon']) : '';
        $array['closedon_formatted'] = $array['closedon'] ? date('Y-m-d H:i:s', (int) $array['closedon']) : '';

        $array['author'] = $this->username((int) $this->object->get('author_id'));
        $assignee = $this->username((int) $this->object->get('assignee_id'));
        $array['assignee'] = $assignee !== '' ? $assignee : null;

        $array['column_key'] = '';
        $array['column_name'] = '';
        $column = $this->modx->getObject(MxBoardColumn::class, (int) $this->object->get('column_id'));
        if ($column) {
            $array['column_key'] = (string) $column->get('key');
            $array['column_name'] = (string) $column->get('name');
        }

        $array['comments'] = $this->comments($taskId);
        $array['log'] = $this->log($taskId);

        return $this->success('', $array);
    }

    /**
     * Комментарии по возрастанию времени — читаются как тред.
     *
     * @return list<array<string, mixed>>
     */
    protected function comments(int $taskId): array
    {
        $c = $this->modx->newQuery(MxBoardComment::class);
        $c->leftJoin(modUser::class, 'User');
        $c->where(['MxBoardComment.task_id' => $taskId]);
        $c->select($this->modx->getSelectColumns(MxBoardComment::class, 'MxBoardComment'));
        $c->select(['User.username AS username']);
        $c->sortby('MxBoardComment.createdon', 'ASC');
        $c->sortby('MxBoardComment.id', 'ASC');

        $rows = $this->fetch($c);
        foreach ($rows as &$row) {
            $row['username'] = (string) ($row['username'] ?? '');
            $row['createdon_formatted'] = $row['createdon'] ? date('Y-m-d H:i:s', (int) $row['createdon']) : '';
        }

        return $rows;
    }

    /**
     * Журнал переходов по возрастанию времени.
     *
     * @return list<array<string, mixed>>
     */
    protected function log(int $taskId): array
    {
        $c = $this->modx->newQuery(MxBoardLog::class);
        $c->leftJoin(modUser::class, 'User');
        $c->where(['MxBoardLog.task_id' => $taskId]);
        $c->select($this->modx->getSelectColumns(MxBoardLog::class, 'MxBoardLog'));
        $c->select(['User.username AS username']);
        $c->sortby('MxBoardLog.createdon', 'ASC');
        $c->sortby('MxBoardLog.id', 'ASC');

        $rows = $this->fetch($c);
        foreach ($rows as &$row) {
            $row['username'] = (string) ($row['username'] ?? '');
            $row['createdon_formatted'] = $row['createdon'] ? date('Y-m-d H:i:s', (int) $row['createdon']) : '';
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetch(\xPDO\Om\xPDOQuery $c): array
    {
        if (!$c->prepare() || !$c->stmt->execute()) {
            return [];
        }

        return $c->stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    protected function username(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $user = $this->modx->getObject(modUser::class, $userId);

        return $user ? (string) $user->get('username') : '';
    }
}
