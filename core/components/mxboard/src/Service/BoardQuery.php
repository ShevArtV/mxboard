<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modUserGroupMember;
use MODX\Revolution\modX;
use MxBoard\Helpers\Visibility;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardDepartment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;

/**
 * Слой чтения доски — общий для MCP и REST.
 *
 * Возвращает СТРУКТУРЫ (массивы), а не текст/JSON: MCP рендерит их в текст для агента,
 * REST отдаёт как JSON. Правило одно, представлений два. Видимость — через Visibility,
 * ровно как в остальных каналах.
 */
class BoardQuery
{
    /** Потолки выдачи: обзор доски, а не дамп базы в контекст агента. */
    private const MAX_TASKS = 300;

    /** @var array<int, string> кеш username по userId — предотвращает N+1 в taskDetail. */
    private array $usernameCache = [];

    public function __construct(private modX $modx)
    {
    }

    /**
     * Список активных проектов (ключ, имя, отдел). Имена проектов не секретны —
     * скоуп по видимости применяется уже на уровне карточек.
     *
     * @return list<array<string, mixed>>
     */
    public function projects(): array
    {
        $c = $this->modx->newQuery(MxBoardProject::class);
        $c->where(['active' => true]);
        $c->sortby('position', 'ASC');

        $out = [];
        /** @var MxBoardProject $project */
        foreach ($this->modx->getCollection(MxBoardProject::class, $c) as $project) {
            $out[] = [
                'id' => (int) $project->get('id'),
                'key' => (string) $project->get('key'),
                'name' => (string) $project->get('name'),
                'description' => (string) $project->get('description'),
                'department_id' => (int) $project->get('department_id'),
                'active' => (bool) $project->get('active'),
                'position' => (int) $project->get('position'),
            ];
        }

        return $out;
    }

    /**
     * Список отделов (реестр). Имена/группы не секретны — скоуп по видимости на карточках.
     *
     * @return list<array<string, mixed>>
     */
    public function departments(): array
    {
        $c = $this->modx->newQuery(MxBoardDepartment::class);
        $c->where(['active' => true]);
        $c->sortby('position', 'ASC');

        $out = [];
        /** @var MxBoardDepartment $department */
        foreach ($this->modx->getCollection(MxBoardDepartment::class, $c) as $department) {
            $out[] = [
                'id' => (int) $department->get('id'),
                'usergroup_id' => (int) $department->get('usergroup_id'),
                'name' => (string) $department->get('name'),
            ];
        }

        return $out;
    }

    /**
     * Типы задач отдела (для формы создания: выбор типа).
     *
     * @return list<array<string, mixed>>
     */
    public function types(int $departmentId): array
    {
        $c = $this->modx->newQuery(MxBoardTaskType::class);
        $c->where(['department_id' => $departmentId, 'active' => true]);
        $c->sortby('position', 'ASC');

        $out = [];
        /** @var MxBoardTaskType $type */
        foreach ($this->modx->getCollection(MxBoardTaskType::class, $c) as $type) {
            $out[] = [
                'id' => (int) $type->get('id'),
                'key' => (string) $type->get('key'),
                'name' => (string) $type->get('name'),
                'description' => (string) $type->get('description'),
                'ai_check' => (bool) $type->get('ai_check'),
                'ai_prompt' => (string) $type->get('ai_prompt'),
            ];
        }

        return $out;
    }

    /**
     * Колонки/стадии проекта (для админки стадий и заголовков доски).
     *
     * @return list<array<string, mixed>>
     */
    public function columns(int $projectId): array
    {
        $c = $this->modx->newQuery(MxBoardColumn::class);
        $c->where(['project_id' => $projectId]);
        $c->sortby('position', 'ASC');

        $out = [];
        /** @var MxBoardColumn $column */
        foreach ($this->modx->getCollection(MxBoardColumn::class, $c) as $column) {
            $out[] = [
                'id' => (int) $column->get('id'),
                'key' => (string) $column->get('key'),
                'name' => (string) $column->get('name'),
                'position' => (int) $column->get('position'),
                'move_roles' => (string) $column->get('move_roles'),
                'stage_key' => (string) $column->get('stage_key'),
                'color' => (string) ($column->get('color') ?: '#6c757d'),
                'is_initial' => (bool) $column->get('is_initial'),
                'is_final' => (bool) $column->get('is_final'),
            ];
        }

        return $out;
    }

    /**
     * Пользователи, которых можно назначить исполнителем в проектах отдела — члены его группы.
     *
     * @return list<array<string, mixed>>
     */
    public function departmentUsers(int $departmentId): array
    {
        /** @var MxBoardDepartment|null $department */
        $department = $this->modx->getObject(MxBoardDepartment::class, $departmentId);
        $usergroupId = $department ? (int) $department->get('usergroup_id') : 0;
        if ($usergroupId <= 0) {
            return [];
        }

        $c = $this->modx->newQuery(modUserGroupMember::class);
        $c->innerJoin(modUser::class, 'User');
        $c->where(['modUserGroupMember.user_group' => $usergroupId, 'User.active' => true]);
        $c->select(['user_id' => 'User.id', 'username' => 'User.username']);
        $c->sortby('User.username', 'ASC');

        $c->prepare();
        if (!$c->stmt || !$c->stmt->execute()) {
            return [];
        }

        $out = [];
        foreach ((array) $c->stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[] = ['id' => (int) $row['user_id'], 'username' => (string) $row['username']];
        }

        return $out;
    }

    /**
     * Доска проекта: колонки и видимые карточки в них.
     *
     * @param array<string, mixed> $filters column (ключ), mine (bool), author_id, assignee_id
     *
     * @return array{project: array<string, mixed>, columns: list<array<string, mixed>>}
     */
    public function board(modUser $user, MxBoardProject $project, array $filters = []): array
    {
        $projectId = (int) $project->get('id');
        $userId = (int) $user->get('id');

        $columnKey = trim((string) ($filters['column'] ?? ''));

        $cq = $this->modx->newQuery(MxBoardColumn::class);
        $cq->where($columnKey !== '' ? ['project_id' => $projectId, 'key' => $columnKey] : ['project_id' => $projectId]);
        $cq->sortby('position', 'ASC');
        /** @var MxBoardColumn[] $columns */
        $columns = $this->modx->getCollection(MxBoardColumn::class, $cq);

        $rows = $this->fetchTasks($user, $project, $filters);

        /** @var array<string, list<array<string, mixed>>> $byColumn */
        $byColumn = [];
        foreach ($rows as $row) {
            $byColumn[(string) $row['column_key']][] = $row;
        }

        $cols = [];
        foreach ($columns as $column) {
            $key = (string) $column->get('key');
            $cols[] = [
                'key' => $key,
                'name' => (string) $column->get('name'),
                'is_initial' => (bool) $column->get('is_initial'),
                'is_final' => (bool) $column->get('is_final'),
                'stage_key' => (string) $column->get('stage_key'),
                'color' => (string) ($column->get('color') ?: '#6c757d'),
                'tasks' => $byColumn[$key] ?? [],
            ];
        }

        return [
            'project' => [
                'id' => $projectId,
                'key' => (string) $project->get('key'),
                'name' => (string) $project->get('name'),
            ],
            'columns' => $cols,
        ];
    }

    /**
     * Детальный просмотр карточки, если пользователю можно (canView). Иначе null.
     *
     * Отдаёт саму карточку, родителя (кратко), подзадачи и комментарии.
     *
     * @return array<string, mixed>|null
     */
    public function taskDetail(modUser $user, MxBoardTask $task): ?array
    {
        if (!Visibility::canView($this->modx, $user, $task)) {
            return null;
        }

        $out = $task->toArray();
        $out['column_key'] = $this->columnKey((int) $task->get('column_id'));
        $out['type_key'] = $this->typeKey((int) $task->get('type_id'));
        $out['author'] = $this->username((int) $task->get('author_id'));
        $out['assignee'] = $this->username((int) $task->get('assignee_id'));

        $parentId = (int) $task->get('parent_id');
        if ($parentId > 0) {
            /** @var MxBoardTask|null $parent */
            $parent = $this->modx->getObject(MxBoardTask::class, $parentId);
            $out['parent'] = $parent
                ? ['id' => $parentId, 'title' => (string) $parent->get('title')]
                : null;
        } else {
            $out['parent'] = null;
        }

        // Подзадачи.
        $sq = $this->modx->newQuery(MxBoardTask::class);
        $sq->where(['parent_id' => (int) $task->get('id')]);
        $sq->sortby('createdon', 'ASC');
        $subtasks = [];
        /** @var MxBoardTask $sub */
        foreach ($this->modx->getCollection(MxBoardTask::class, $sq) as $sub) {
            $subtasks[] = [
                'id' => (int) $sub->get('id'),
                'title' => (string) $sub->get('title'),
                'assignee' => $this->username((int) $sub->get('assignee_id')),
                'closed' => (int) $sub->get('closedon') > 0,
            ];
        }
        $out['subtasks'] = $subtasks;

        // Комментарии.
        $mq = $this->modx->newQuery(MxBoardComment::class);
        $mq->where(['task_id' => (int) $task->get('id')]);
        $mq->sortby('createdon', 'ASC');
        $comments = [];
        /** @var MxBoardComment $comment */
        foreach ($this->modx->getCollection(MxBoardComment::class, $mq) as $comment) {
            $comments[] = [
                'user' => $this->username((int) $comment->get('user_id')),
                'content' => (string) $comment->get('content'),
                'createdon' => (int) $comment->get('createdon'),
            ];
        }
        $out['comments'] = $comments;

        return $out;
    }

    /**
     * Журнал переходов задачи по возрастанию времени.
     *
     * Права здесь НЕ проверяются: вызывающий обязан сперва пройти taskDetail/canView.
     * Журнал показываем видящему задачу целиком — по нему видно, кто что реально делал.
     *
     * @return list<array<string, mixed>>
     */
    public function taskLog(int $taskId): array
    {
        $c = $this->modx->newQuery(MxBoardLog::class);
        $c->leftJoin(modUser::class, 'User');
        $c->where(['MxBoardLog.task_id' => $taskId]);
        $c->select([
            'MxBoardLog.action',
            'MxBoardLog.from_column',
            'MxBoardLog.to_column',
            'MxBoardLog.note',
            'MxBoardLog.channel',
            'MxBoardLog.createdon',
            'user' => 'User.username',
        ]);
        $c->sortby('MxBoardLog.createdon', 'ASC');
        $c->sortby('MxBoardLog.id', 'ASC');

        $c->prepare();
        if (!$c->stmt || !$c->stmt->execute()) {
            return [];
        }

        return (array) $c->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Схема типа: встроенные обязательные поля (title, deadline) + поля типа.
     *
     * @return array<string, mixed>|null null — тип не найден в отделе проекта
     */
    public function typeSchema(MxBoardProject $project, string $typeKey): ?array
    {
        $departmentId = (int) $project->get('department_id');
        /** @var MxBoardTaskType|null $type */
        $type = $this->modx->getObject(MxBoardTaskType::class, [
            'department_id' => $departmentId,
            'key' => $typeKey,
            'active' => true,
        ]);
        if (!$type) {
            return null;
        }

        $fq = $this->modx->newQuery(MxBoardField::class);
        $fq->where(['task_type_id' => (int) $type->get('id')]);
        $fq->sortby('position', 'ASC');
        $fields = [];
        /** @var MxBoardField $field */
        foreach ($this->modx->getCollection(MxBoardField::class, $fq) as $field) {
            $fields[] = [
                'key' => (string) $field->get('key'),
                'label' => (string) $field->get('label'),
                'type' => (string) $field->get('type'),
                'required' => (bool) $field->get('required'),
                'options' => $field->get('options'),
            ];
        }

        return [
            'type' => [
                'key' => (string) $type->get('key'),
                'name' => (string) $type->get('name'),
                'ai_check' => (bool) $type->get('ai_check'),
            ],
            // Встроенные поля есть у любой задачи и в mxboard_field не описываются.
            'builtin' => [
                ['key' => 'title', 'label' => 'Заголовок', 'type' => 'text', 'required' => true],
                ['key' => 'deadline', 'label' => 'Дедлайн', 'type' => 'date', 'required' => true],
            ],
            'fields' => $fields,
        ];
    }

    /**
     * Карточки доски одним запросом с именами автора/исполнителя (иначе N+1),
     * с учётом видимости (свои — или все, если менеджер).
     *
     * @param array<string, mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    private function fetchTasks(modUser $user, MxBoardProject $project, array $filters): array
    {
        $projectId = (int) $project->get('id');
        $userId = (int) $user->get('id');

        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->innerJoin(MxBoardColumn::class, 'Column');
        $c->leftJoin(modUser::class, 'Author');
        $c->leftJoin(modUser::class, 'Assignee');
        $c->leftJoin(MxBoardTaskType::class, 'Type');

        $c->where(['MxBoardTask.project_id' => $projectId]);

        $columnKey = trim((string) ($filters['column'] ?? ''));
        if ($columnKey !== '') {
            $c->where(['Column.key' => $columnKey]);
        }
        if (!empty($filters['mine'])) {
            $c->where(['MxBoardTask.assignee_id' => $userId]);
        }
        if (!empty($filters['author_id'])) {
            $c->where(['MxBoardTask.author_id' => (int) $filters['author_id']]);
        }
        if (!empty($filters['assignee_id'])) {
            $c->where(['MxBoardTask.assignee_id' => (int) $filters['assignee_id']]);
        }

        // Скоуп видимости: свои карточки — либо все, если менеджер (пустое условие).
        $visibility = Visibility::boardCondition($this->modx, $user, $project);
        if (!empty($visibility)) {
            $c->where($visibility);
        }

        $c->select([
            'MxBoardTask.id',
            'MxBoardTask.title',
            'MxBoardTask.priority',
            'MxBoardTask.assignee_id',
            'MxBoardTask.author_id',
            'MxBoardTask.parent_id',
            'MxBoardTask.deadlineon',
            'MxBoardTask.deadline_disputed',
            'MxBoardTask.closedon',
            'column_key' => 'Column.key',
            'type_key' => 'Type.key',
            'author' => 'Author.username',
            'assignee' => 'Assignee.username',
        ]);

        $c->sortby('Column.position', 'ASC');
        $c->sortby('MxBoardTask.priority', 'DESC');
        $c->sortby('MxBoardTask.position', 'ASC');
        $c->limit(self::MAX_TASKS);

        $c->prepare();
        if (!$c->stmt || !$c->stmt->execute()) {
            return [];
        }

        return (array) $c->stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function columnKey(int $columnId): string
    {
        /** @var MxBoardColumn|null $column */
        $column = $columnId > 0 ? $this->modx->getObject(MxBoardColumn::class, $columnId) : null;

        return $column ? (string) $column->get('key') : '';
    }

    private function typeKey(int $typeId): string
    {
        /** @var MxBoardTaskType|null $type */
        $type = $typeId > 0 ? $this->modx->getObject(MxBoardTaskType::class, $typeId) : null;

        return $type ? (string) $type->get('key') : '';
    }

    private function username(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        if (isset($this->usernameCache[$userId])) {
            return $this->usernameCache[$userId];
        }
        /** @var modUser|null $user */
        $user = $this->modx->getObject(modUser::class, $userId);
        $name = $user ? (string) $user->get('username') : ('#' . $userId);
        $this->usernameCache[$userId] = $name;

        return $name;
    }
}
