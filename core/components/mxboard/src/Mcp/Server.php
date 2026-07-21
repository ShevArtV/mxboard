<?php

declare(strict_types=1);

namespace MxBoard\Mcp;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\BoardQuery;
use MxBoard\Service\StructureService;
use MxBoard\Service\TaskService;

/**
 * MCP-сервер mxBoard: JSON-RPC 2.0 поверх Streamable HTTP.
 *
 * Транспорт (заголовки, HTTP-коды, авторизация) — в assets/components/mxboard/mcp.php.
 * Здесь только протокол и инструменты: класс ничего не печатает и не завершает процесс,
 * handle() возвращает массив ответа либо null для нотификации.
 *
 * Правила доски здесь НЕ дублируются: всё идёт через TaskService/StructureService/BoardQuery
 * с каналом 'mcp' от имени пользователя токена. Отсюда модель прав: агент физически не может
 * закрыть чужую задачу — не потому, что мы это проверяем, а потому что это запрещено его
 * пользователю. `tools/list` фильтруется по правам: структурные тулы видит только менеджер.
 */
final class Server
{
    /** Ревизия спеки MCP, которую заявляем в initialize. */
    public const PROTOCOL_VERSION = '2025-06-18';

    private const SERVER_NAME = 'mxboard';
    private const SERVER_VERSION = '2.0.0';

    /** Канал для журнала переходов: отличает действия агента от менеджера и REST. */
    private const CHANNEL = 'mcp';

    private const MAX_PER_COLUMN = 30;

    private TaskService $tasks;
    private StructureService $structure;
    private BoardQuery $query;

    public function __construct(private modX $modx, private modUser $user)
    {
        $this->tasks = new TaskService($modx);
        $this->structure = new StructureService($modx);
        $this->query = new BoardQuery($modx);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null null — на нотификацию отвечать нечем (по JSON-RPC)
     */
    public function handle(array $request): ?array
    {
        $isNotification = !array_key_exists('id', $request) || $request['id'] === null;
        if ($isNotification) {
            return null;
        }

        $id = $request['id'];
        $method = isset($request['method']) && is_string($request['method']) ? $request['method'] : '';

        if (($request['jsonrpc'] ?? '') !== '2.0' || $method === '') {
            return $this->error($id, -32600, 'Invalid Request: expected jsonrpc "2.0" and a method');
        }

        $params = isset($request['params']) && is_array($request['params']) ? $request['params'] : [];

        try {
            $result = match ($method) {
                'initialize' => $this->initialize(),
                'ping' => (object) [],
                'tools/list' => ['tools' => $this->tools()],
                'tools/call' => $this->callTool($params),
                default => null,
            };
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard][mcp] ' . $method . ': ' . $e->getMessage());

            return $this->error($id, -32603, 'Internal error');
        }

        if ($result === null) {
            return $this->error($id, -32601, 'Method not found: ' . $method);
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            'instructions' => 'Канбан-доски mxBoard (отдел → проект → задача). Порядок: project_list — '
                . 'какие проекты есть; board_list — что на доске проекта; task_schema — какие поля нужны для типа; '
                . 'department_users — кого можно назначить исполнителем; task_create — поставить задачу (тип, '
                . 'дедлайн, поля и ИСПОЛНИТЕЛЬ из отдела проекта обязательны); task_comment — отчитаться; '
                . 'task_move — перевести. Исполнитель назначается при создании, свободного пула нет. Незавершённая '
                . 'подзадача блокирует закрытие родителя. Всё пишется в журнал от вашего пользователя.',
        ];
    }

    /**
     * Набор инструментов. Структурные (создание типов/проектов/отделов) видит только
     * менеджер отдела — чтобы контекст исполнителя не раздувался тем, чем он не пользуется.
     *
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        $tools = $this->baseTools();

        if (Transitions::isAnyDepartmentManager($this->modx, $this->user)) {
            $tools = array_merge($tools, $this->structureTools());
        }

        return $tools;
    }

    /** @return list<array<string, mixed>> */
    private function baseTools(): array
    {
        return [
            $this->tool('project_list', 'Список проектов (отдел, ключ, имя).', []),
            $this->tool('department_list', 'Список отделов (id, группа, имя).', []),
            $this->tool('type_list', 'Типы задач отдела проекта (для task_create).', [
                'project' => ['type' => 'string', 'description' => 'Ключ проекта. По умолчанию — из настроек.'],
            ]),
            $this->tool('stage_list', 'Стадии (колонки) проекта.', [
                'project' => ['type' => 'string', 'description' => 'Ключ проекта. По умолчанию — из настроек.'],
            ]),
            $this->tool('board_list', 'Что на доске проекта: колонки и видимые карточки.', [
                'project' => ['type' => 'string', 'description' => 'Ключ проекта. По умолчанию — из настроек.'],
                'column' => ['type' => 'string', 'description' => 'Ключ колонки: показать только её.'],
                'mine' => ['type' => 'boolean', 'description' => 'Только карточки, взятые вами.'],
            ]),
            $this->tool('task_get', 'Карточка целиком: поля, родитель, подзадачи, комментарии.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
            ], ['task_id']),
            $this->tool('task_schema', 'Какие поля нужны для типа задачи (встроенные + обязательные).', [
                'type' => ['type' => 'string', 'description' => 'Ключ типа задачи.'],
                'project' => ['type' => 'string', 'description' => 'Ключ проекта (для определения отдела). По умолчанию — из настроек.'],
            ], ['type']),
            $this->tool('task_create', 'Поставить задачу. Обязательны: тип, заголовок, дедлайн, поля типа и ИСПОЛНИТЕЛЬ (член отдела проекта — см. department_users). Автором станете вы.', [
                'type' => ['type' => 'string', 'description' => 'Ключ типа задачи (см. project → task_schema).'],
                'title' => ['type' => 'string', 'description' => 'Заголовок (≤250).'],
                'deadline' => ['type' => 'string', 'description' => 'Дедлайн: дата YYYY-MM-DD или unix-время.'],
                'assignee' => ['type' => 'string', 'description' => 'Исполнитель: username или id. Строго из отдела проекта (department_users).'],
                'fields' => ['type' => 'object', 'description' => 'Значения полей типа: {ключ_поля: значение}.'],
                'project' => ['type' => 'string', 'description' => 'Ключ проекта. По умолчанию — из настроек.'],
                'tor' => ['type' => 'string', 'description' => 'Постановка (ToR) в markdown.'],
                'priority' => ['type' => 'integer', 'description' => 'Приоритет, больше — важнее.'],
                'plan_hours' => ['type' => 'integer', 'description' => 'Плановое время в часах. Необязательно; исполнитель вправе оспорить (task_dispute_plan).'],
                'parent_id' => ['type' => 'integer', 'description' => 'ID родителя → создать как подзадачу (нужно быть автором/исполнителем родителя).'],
                'meta' => ['type' => 'object', 'description' => 'Произвольные метаданные интегратора.'],
            ], ['type', 'title', 'deadline', 'assignee']),
            $this->tool('department_users', 'Кого можно назначить исполнителем — члены отдела проекта.', [
                'project' => ['type' => 'string', 'description' => 'Ключ проекта. По умолчанию — из настроек.'],
            ]),
            $this->tool('task_move', 'Перевести карточку в колонку по ключу. Если правила не пускают — ошибка с причиной.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'column' => ['type' => 'string', 'description' => 'Ключ колонки назначения.'],
                'note' => ['type' => 'string', 'description' => 'Пояснение — попадёт в журнал.'],
            ], ['task_id', 'column']),
            $this->tool('task_comment', 'Комментарий к карточке: так вы отчитываетесь о ходе.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'content' => ['type' => 'string', 'description' => 'Текст (markdown).'],
            ], ['task_id', 'content']),
            $this->tool('task_comment_edit', 'Редактировать свой комментарий.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'comment_id' => ['type' => 'integer', 'description' => 'ID комментария.'],
                'content' => ['type' => 'string', 'description' => 'Новый текст (markdown).'],
            ], ['task_id', 'comment_id', 'content']),
            $this->tool('task_comment_delete', 'Удалить свой комментарий.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'comment_id' => ['type' => 'integer', 'description' => 'ID комментария.'],
            ], ['task_id', 'comment_id']),
            $this->tool('task_dispute_deadline', 'Оспорить дедлайн (исполнитель): предложить новую дату с причиной. Меняет её автор.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'proposed_date' => ['type' => 'string', 'description' => 'Предлагаемая дата: YYYY-MM-DD или unix.'],
                'reason' => ['type' => 'string', 'description' => 'Почему нужен перенос.'],
            ], ['task_id', 'proposed_date']),
            $this->tool('task_update', 'Правка карточки (автор/менеджер): заголовок, дедлайн, план, приоритет, тип, поля, ToR.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'title' => ['type' => 'string'],
                'deadline' => ['type' => 'string', 'description' => 'YYYY-MM-DD или unix.'],
                'plan_hours' => ['type' => 'integer', 'description' => 'Плановое время в часах; 0 — снять оценку.'],
                'priority' => ['type' => 'integer'],
                'type' => ['type' => 'string', 'description' => 'Новый ключ типа.'],
                'fields' => ['type' => 'object'],
                'tor' => ['type' => 'string'],
            ], ['task_id']),
            $this->tool('task_resolve_dispute', 'Разрешить оспаривание дедлайна (автор/менеджер): принять или отклонить.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'accept' => ['type' => 'boolean', 'description' => 'true — принять предложенную дату; false — отклонить.'],
            ], ['task_id', 'accept']),
            $this->tool('task_dispute_plan', 'Оспорить плановое время (исполнитель): предложить свою оценку в часах с причиной. Меняет её автор.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'proposed_hours' => ['type' => 'integer', 'description' => 'Предлагаемая оценка в часах.'],
                'reason' => ['type' => 'string', 'description' => 'Почему оценка не годится.'],
            ], ['task_id', 'proposed_hours']),
            $this->tool('task_resolve_plan', 'Разрешить оспаривание планового времени (автор/менеджер): принять или отклонить.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
                'accept' => ['type' => 'boolean', 'description' => 'true — принять предложенную оценку; false — отклонить.'],
            ], ['task_id', 'accept']),
            $this->tool('task_delete', 'Удалить карточку (автор/менеджер). Подзадачи открепляются, не удаляются.', [
                'task_id' => ['type' => 'string', 'description' => 'Адрес карточки: id (число) или num (напр. 2607-15).'],
            ], ['task_id']),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function structureTools(): array
    {
        return [
            $this->tool('department_register', 'Пометить группу пользователей MODX как отдел (менеджер).', [
                'usergroup_id' => ['type' => 'integer', 'description' => 'ID группы MODX.'],
                'name' => ['type' => 'string', 'description' => 'Имя отдела (по умолчанию — имя группы).'],
            ], ['usergroup_id']),
            $this->tool('type_create', 'Создать тип задачи с полями (менеджер). Нужно ≥1 поле.', [
                'department_id' => ['type' => 'integer', 'description' => 'ID отдела.'],
                'key' => ['type' => 'string', 'description' => 'Ключ типа.'],
                'name' => ['type' => 'string', 'description' => 'Название.'],
                'description' => ['type' => 'string'],
                'fields' => [
                    'type' => 'array',
                    'description' => 'Поля: [{key, label, type(text|textarea|url|number|date|select|user|files), required(bool), options?}]. `options` — только для select. Минимум одно.',
                    'items' => ['type' => 'object'],
                ],
            ], ['department_id', 'key', 'name', 'fields']),
            $this->tool('project_create', 'Создать проект (менеджер). Колонки опциональны: без них проект показывает дефолтный шаблон, свои задаются позже копированием. Если переданы — ровно одна initial и одна final.', [
                'department_id' => ['type' => 'integer', 'description' => 'ID отдела.'],
                'key' => ['type' => 'string', 'description' => 'Ключ проекта.'],
                'name' => ['type' => 'string', 'description' => 'Название.'],
                'description' => ['type' => 'string'],
                'columns' => [
                    'type' => 'array',
                    'description' => 'Колонки: [{key, name, move_roles, color?, is_initial, is_final}]. Пусто — проект без своих колонок (доска покажет дефолтный шаблон).',
                    'items' => ['type' => 'object'],
                ],
            ], ['department_id', 'key', 'name']),
            $this->tool('stage_create', 'Создать стадию проекта (менеджер).', [
                'project' => ['type' => 'string', 'description' => 'Ключ проекта. По умолчанию — из настроек.'],
                'project_id' => ['type' => 'integer', 'description' => 'ID проекта; 0 — шаблон новых проектов (только sudo).'],
                'key' => ['type' => 'string', 'description' => 'Ключ стадии.'],
                'name' => ['type' => 'string', 'description' => 'Название стадии.'],
                'description' => ['type' => 'string', 'description' => 'Описание стадии. Может быть пустым.'],
                'move_roles' => ['type' => 'string', 'description' => 'CSV ролей: author, assignee, group:Name.'],
                'color' => ['type' => 'string', 'description' => 'Цвет #RRGGBB.'],
                'position' => ['type' => 'integer', 'description' => 'Позиция сортировки.'],
            ], ['key', 'name']),
            $this->tool('stage_update', 'Правка стадии проекта (менеджер).', [
                'stage_id' => ['type' => 'integer', 'description' => 'ID стадии.'],
                'name' => ['type' => 'string', 'description' => 'Название стадии.'],
                'description' => ['type' => 'string', 'description' => 'Описание стадии. Может быть пустым.'],
                'move_roles' => ['type' => 'string', 'description' => 'CSV ролей: author, assignee, group:Name.'],
                'color' => ['type' => 'string', 'description' => 'Цвет #RRGGBB.'],
                'position' => ['type' => 'integer', 'description' => 'Позиция сортировки.'],
                'is_initial' => ['type' => 'boolean', 'description' => 'true переносит флаг начальной стадии сюда.'],
                'is_final' => ['type' => 'boolean', 'description' => 'true переносит флаг финальной стадии сюда.'],
                'is_start' => ['type' => 'boolean', 'description' => 'Стартовая стадия: с неё идёт отсчёт фактического времени. true переносит флаг сюда, false — снимает (замера не будет). Начальной стадией быть не может.'],
            ], ['stage_id']),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                         $required
     *
     * @return array<string, mixed>
     */
    private function tool(string $name, string $description, array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => (object) $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return ['name' => $name, 'description' => $description, 'inputSchema' => $schema];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

        return match ($name) {
            'project_list' => $this->projectList(),
            'department_list' => $this->departmentList(),
            'type_list' => $this->typeList($args),
            'stage_list' => $this->stageList($args),
            'board_list' => $this->boardList($args),
            'task_get' => $this->taskGet($args),
            'task_schema' => $this->taskSchema($args),
            'department_users' => $this->departmentUsers($args),
            'task_create' => $this->taskCreate($args),
            'task_move' => $this->taskMove($args),
            'task_comment' => $this->taskComment($args),
            'task_comment_edit' => $this->taskCommentEdit($args),
            'task_comment_delete' => $this->taskCommentDelete($args),
            'task_dispute_deadline' => $this->taskDispute($args),
            'task_update' => $this->taskUpdate($args),
            'task_resolve_dispute' => $this->taskResolve($args),
            'task_dispute_plan' => $this->taskDisputePlan($args),
            'task_resolve_plan' => $this->taskResolvePlan($args),
            'task_delete' => $this->taskDelete($args),
            'department_register' => $this->result($this->structure->registerDepartment($this->user, $this->structureArgs($args))),
            'type_create' => $this->result($this->structure->createType($this->user, $this->structureArgs($args))),
            'project_create' => $this->result($this->structure->createProject($this->user, $this->structureArgs($args))),
            'stage_create' => $this->stageCreate($args),
            'stage_update' => $this->stageUpdate($args),
            default => throw new \InvalidArgumentException('Unknown tool: ' . $name),
        };
    }

    /** @return array<string, mixed> */
    private function projectList(): array
    {
        $projects = $this->query->projects();
        if (!$projects) {
            return $this->content('Проектов нет.');
        }

        $out = ['Проекты:'];
        foreach ($projects as $p) {
            $out[] = '  [' . $p['key'] . '] ' . $p['name'] . ' (отдел #' . $p['department_id'] . ')';
        }

        return $this->content(implode("\n", $out));
    }

    /** @return array<string, mixed> */
    private function departmentList(): array
    {
        $departments = $this->query->departments();
        if (!$departments) {
            return $this->content('Отделов нет.');
        }

        $out = ['Отделы:'];
        foreach ($departments as $d) {
            $out[] = '  #' . $d['id'] . ' ' . $d['name'] . ' (группа #' . $d['usergroup_id'] . ')';
        }

        return $this->content(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function typeList(array $args): array
    {
        $project = $this->resolveProject($this->str($args['project'] ?? null));
        if (!$project) {
            return $this->content($this->lex('mxboard_err_project_not_found'), true);
        }

        $types = $this->query->types((int) $project->get('department_id'));
        if (!$types) {
            return $this->content('В отделе проекта нет типов задач.');
        }

        $out = ['Типы задач (отдел проекта [' . $project->get('key') . ']):'];
        foreach ($types as $t) {
            $out[] = '  [' . $t['key'] . '] ' . $t['name'];
        }

        return $this->content(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function stageList(array $args): array
    {
        $project = $this->resolveProject($this->str($args['project'] ?? null));
        if (!$project) {
            return $this->content($this->lex('mxboard_err_project_not_found'), true);
        }

        $columns = $this->query->columns((int) $project->get('id'));
        if (!$columns) {
            return $this->content('У проекта нет стадий.');
        }

        $out = ['Стадии проекта [' . $project->get('key') . ']:'];
        foreach ($columns as $col) {
            $flags = [];
            if ($col['is_initial']) {
                $flags[] = 'начальная';
            }
            if (!empty($col['is_start'])) {
                $flags[] = 'стартовая: отсюда идёт отсчёт факта';
            }
            if ($col['is_final']) {
                $flags[] = 'финальная';
            }
            $line = '  [' . $col['key'] . '] ' . $col['name'] . ($flags ? ' (' . implode(', ', $flags) . ')' : '');
            if (!empty($col['description'])) {
                $line .= ' — ' . (string) $col['description'];
            }
            $out[] = $line;
        }

        return $this->content(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function boardList(array $args): array
    {
        $project = $this->resolveProject($this->str($args['project'] ?? null));
        if (!$project) {
            return $this->content($this->lex('mxboard_err_project_not_found'), true);
        }

        $data = $this->query->board($this->user, $project, [
            'column' => $this->str($args['column'] ?? null),
            'mine' => !empty($args['mine']),
        ]);

        $userId = (int) $this->user->get('id');
        $out = [];
        $out[] = 'Проект: ' . $data['project']['name'] . ' [' . $data['project']['key'] . ']';
        $out[] = 'Вы: ' . $this->user->get('username') . ' (#' . $userId . ')'
            . (!empty($args['mine']) ? ' — только ваши карточки' : '');
        $out[] = '';

        foreach ($data['columns'] as $column) {
            $flags = [];
            if (!empty($column['is_initial'])) {
                $flags[] = 'старт';
            }
            if (!empty($column['is_final'])) {
                $flags[] = 'финальная';
            }
            $tasks = $column['tasks'];
            $out[] = '[' . $column['key'] . '] ' . $column['name'] . ' — ' . count($tasks)
                . ($flags ? ' (' . implode(', ', $flags) . ')' : '');
            if (!empty($column['description'])) {
                $out[] = '  ' . (string) $column['description'];
            }

            if (!$tasks) {
                $out[] = '  пусто';
                $out[] = '';
                continue;
            }
            foreach (array_slice($tasks, 0, self::MAX_PER_COLUMN) as $row) {
                $out[] = '  ' . $this->taskLine($row, $userId);
            }
            $hidden = count($tasks) - self::MAX_PER_COLUMN;
            if ($hidden > 0) {
                $out[] = '  … и ещё ' . $hidden;
            }
            $out[] = '';
        }

        return $this->content(rtrim(implode("\n", $out)));
    }

    /** @param array<string, mixed> $row */
    private function taskLine(array $row, int $userId): string
    {
        $assigneeId = (int) $row['assignee_id'];
        if ($assigneeId === 0) {
            $who = 'свободна';
        } else {
            $name = (string) ($row['assignee'] ?? '') ?: ('#' . $assigneeId);
            $who = 'исполнитель ' . $name . ($assigneeId === $userId ? ' (вы)' : '');
        }

        $priority = (int) $row['priority'];
        $flags = [];
        if ((int) ($row['parent_id'] ?? 0) > 0) {
            $flags[] = 'подзадача #' . (int) $row['parent_id'];
        }
        if (!empty($row['deadline_disputed'])) {
            $flags[] = 'дедлайн оспорен';
        }

        return '#' . (int) $row['id']
            . (!empty($row['num']) ? ' (' . (string) $row['num'] . ')' : '')
            . ($priority > 0 ? ' · p' . $priority : '')
            . ($row['type_key'] ? ' · ' . (string) $row['type_key'] : '')
            . ' · ' . (string) $row['title']
            . ' · автор ' . ((string) ($row['author'] ?? '') ?: '—')
            . ' · ' . $who
            . ($flags ? ' · ' . implode(', ', $flags) : '');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskGet(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $detail = $this->query->taskDetail($this->user, $task);
        if ($detail === null) {
            return $this->content($this->lex('mxboard_err_view_denied'), true);
        }

        $out = [];
        $out[] = '#' . $detail['id'] . (!empty($detail['num']) ? ' (' . $detail['num'] . ')' : '') . ' · ' . $detail['title'] . ' [' . $detail['type_key'] . ']';
        $out[] = 'Колонка: ' . $detail['column_key'] . ' · автор ' . ($detail['author'] ?: '—')
            . ' · исполнитель ' . ($detail['assignee'] ?: 'свободна');
        $out[] = 'Дедлайн: ' . ($detail['deadlineon'] ? date('Y-m-d', (int) $detail['deadlineon']) : '—')
            . (!empty($detail['deadline_disputed']) ? ' (оспорен → ' . date('Y-m-d', (int) $detail['deadline_proposed']) . ')' : '');
        $out[] = 'План: ' . ((int) ($detail['plan_hours'] ?? 0) > 0 ? (int) $detail['plan_hours'] . ' ч' : '—')
            . (!empty($detail['plan_disputed']) ? ' (оспорен → ' . (int) $detail['plan_proposed'] . ' ч)' : '')
            . ' · факт: ' . $this->factHours($detail);
        if (!empty($detail['parent'])) {
            $out[] = 'Родитель: #' . $detail['parent']['id'] . ' ' . $detail['parent']['title'];
        }
        if (!empty($detail['tor'])) {
            $out[] = "ToR:\n" . $detail['tor'];
        }
        $fields = is_array($detail['fields'] ?? null) ? $detail['fields'] : [];
        if ($fields) {
            $out[] = 'Поля:';
            foreach ($fields as $k => $v) {
                $out[] = '  ' . $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
            }
        }
        if (!empty($detail['subtasks'])) {
            $out[] = 'Подзадачи:';
            foreach ($detail['subtasks'] as $s) {
                $out[] = '  #' . $s['id'] . ' ' . $s['title'] . ' — ' . ($s['closed'] ? 'закрыта' : 'открыта')
                    . ($s['assignee'] ? ' (' . $s['assignee'] . ')' : '');
            }
        }
        if (!empty($detail['comments'])) {
            $out[] = 'Комментарии:';
            foreach ($detail['comments'] as $c) {
                $out[] = '  ' . ($c['user'] ?: '—') . ': ' . $c['content'];
            }
        }

        return $this->content(implode("\n", $out));
    }

    /**
     * Фактическое время карточки словами: замер идёт от входа в стартовую стадию до
     * закрытия. Незакрытая — «идёт», без замера — прочерк (стартовая стадия не помечена
     * либо карточку вернули в бэклог).
     *
     * @param array<string, mixed> $detail
     */
    private function factHours(array $detail): string
    {
        $started = (int) ($detail['startedon'] ?? 0);
        if ($started <= 0) {
            return '—';
        }

        $closed = (int) ($detail['closedon'] ?? 0);
        $hours = (int) round((($closed > 0 ? $closed : time()) - $started) / 3600);

        return $closed > 0 ? $hours . ' ч' : 'идёт, ' . $hours . ' ч';
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskSchema(array $args): array
    {
        $project = $this->resolveProject($this->str($args['project'] ?? null));
        if (!$project) {
            return $this->content($this->lex('mxboard_err_project_not_found'), true);
        }

        $schema = $this->query->typeSchema($project, $this->str($args['type'] ?? null));
        if ($schema === null) {
            return $this->content($this->lex('mxboard_err_type_not_found'), true);
        }

        $out = ['Схема типа [' . $schema['type']['key'] . '] ' . $schema['type']['name'] . ':'];
        $out[] = 'Встроенные (обязательны):';
        foreach ($schema['builtin'] as $f) {
            $out[] = '  ' . $f['key'] . ' (' . $f['type'] . ') — ' . $f['label'];
        }
        $out[] = 'Поля типа:';
        foreach ($schema['fields'] as $f) {
            $out[] = '  ' . $f['key'] . ' (' . $f['type'] . ')' . ($f['required'] ? ' *обязательно*' : '') . ' — ' . $f['label'];
        }

        return $this->content(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskCreate(array $args): array
    {
        $result = $this->tasks->create($this->user, [
            'project' => $this->str($args['project'] ?? null) ?: null,
            'type' => $this->str($args['type'] ?? null),
            'title' => $this->str($args['title'] ?? null),
            'deadline' => $args['deadline'] ?? null,
            'assignee' => $args['assignee'] ?? null,
            'fields' => isset($args['fields']) && is_array($args['fields']) ? $args['fields'] : null,
            'tor' => $this->str($args['tor'] ?? null),
            'priority' => $this->int($args['priority'] ?? null),
            'plan_hours' => $args['plan_hours'] ?? null,
            'parent_id' => $this->int($args['parent_id'] ?? null),
            'meta' => isset($args['meta']) && is_array($args['meta']) ? $args['meta'] : null,
        ], self::CHANNEL);

        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        $task = (array) $result['object'];

        return $this->content('Карточка #' . (int) $task['id'] . (!empty($task['num']) ? ' (' . (string) $task['num'] . ')' : '') . ' «' . (string) $task['title'] . '» создана.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function departmentUsers(array $args): array
    {
        $project = $this->resolveProject($this->str($args['project'] ?? null));
        if (!$project) {
            return $this->content($this->lex('mxboard_err_project_not_found'), true);
        }

        $users = $this->query->departmentUsers((int) $project->get('department_id'));
        if (!$users) {
            return $this->content('В отделе проекта нет пользователей — назначить некого.');
        }

        $out = ['Кого можно назначить исполнителем (отдел проекта [' . $project->get('key') . ']):'];
        foreach ($users as $u) {
            $out[] = '  ' . $u['username'] . ' (#' . $u['id'] . ')';
        }

        return $this->content(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskMove(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');
        $column = $this->str($args['column'] ?? null);
        if ($column === '') {
            return $this->content($this->lex('mxboard_err_column_required'), true);
        }

        $result = $this->tasks->move($this->user, $taskId, $column, $this->str($args['note'] ?? null), self::CHANNEL);
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Карточка #' . $taskId . ' переведена в колонку [' . $column . '].');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskComment(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $result = $this->tasks->comment($this->user, $taskId, $this->str($args['content'] ?? null), self::CHANNEL);
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Комментарий к карточке #' . $taskId . ' добавлен.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskCommentEdit(array $args): array
    {
        $commentId = $this->int($args['comment_id'] ?? null);
        if ($commentId <= 0) {
            return $this->content($this->lex('mxboard_err_comment_not_found'), true);
        }

        $result = $this->tasks->updateComment(
            $this->user,
            $commentId,
            $this->str($args['content'] ?? null),
            self::CHANNEL
        );
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Комментарий #' . $commentId . ' обновлён.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskCommentDelete(array $args): array
    {
        $commentId = $this->int($args['comment_id'] ?? null);
        if ($commentId <= 0) {
            return $this->content($this->lex('mxboard_err_comment_not_found'), true);
        }

        $result = $this->tasks->deleteComment($this->user, $commentId, self::CHANNEL);
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Комментарий #' . $commentId . ' удалён.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskDispute(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $proposed = $args['proposed_date'] ?? null;
        $proposedInt = is_numeric($proposed) ? (int) $proposed : (int) (strtotime((string) $proposed) ?: 0);

        $result = $this->tasks->disputeDeadline(
            $this->user,
            $taskId,
            $proposedInt,
            $this->str($args['reason'] ?? null),
            self::CHANNEL
        );
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Дедлайн карточки #' . $taskId . ' оспорен, ждёт решения автора.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskDisputePlan(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $proposed = $args['proposed_hours'] ?? null;

        $result = $this->tasks->disputePlan(
            $this->user,
            $taskId,
            is_numeric($proposed) ? (int) round((float) $proposed) : 0,
            $this->str($args['reason'] ?? null),
            self::CHANNEL
        );
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Плановое время карточки #' . $taskId . ' оспорено, ждёт решения автора.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskResolvePlan(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $accept = !empty($args['accept']);
        $result = $this->tasks->resolvePlan($this->user, $taskId, $accept, self::CHANNEL);
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Оспаривание плана карточки #' . $taskId . ($accept ? ' принято.' : ' отклонено.'));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskUpdate(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $data = [];
        foreach (['title', 'type', 'tor'] as $k) {
            if (array_key_exists($k, $args)) {
                $data[$k] = $this->str($args[$k]);
            }
        }
        if (array_key_exists('priority', $args)) {
            $data['priority'] = $this->int($args['priority']);
        }
        if (array_key_exists('deadline', $args)) {
            // Сырым, как в taskCreate(): разбор даты централизован в TaskService::normalizeDeadline().
            // Приводить здесь нельзя — (int) "2026-12-31" дал бы 2026 (эпоха 1970-х).
            $data['deadline'] = $args['deadline'];
        }
        if (array_key_exists('plan_hours', $args)) {
            $data['plan_hours'] = $args['plan_hours'];
        }
        if (array_key_exists('fields', $args) && is_array($args['fields'])) {
            $data['fields'] = $args['fields'];
        }

        $result = $this->tasks->update($this->user, $taskId, $data, self::CHANNEL);
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Карточка #' . $taskId . ' обновлена.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskResolve(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $result = $this->tasks->resolveDeadline($this->user, $taskId, !empty($args['accept']), self::CHANNEL);
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Оспаривание дедлайна карточки #' . $taskId . ' ' . (!empty($args['accept']) ? 'принято' : 'отклонено') . '.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskDelete(array $args): array
    {
        $task = $this->tasks->resolveTaskRef($args['task_id'] ?? null);
        if (!$task) {
            return $this->content($this->lex('mxboard_err_task_not_found'), true);
        }
        $taskId = (int) $task->get('id');

        $result = $this->tasks->delete($this->user, $taskId, self::CHANNEL);
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content('Карточка #' . $taskId . ' удалена.');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function stageCreate(array $args): array
    {
        $projectId = $this->stageProjectId($args);
        if ($projectId === null) {
            return $this->content($this->lex('mxboard_err_project_not_found'), true);
        }

        $data = [
            'project_id' => $projectId,
            'key' => $this->str($args['key'] ?? null),
            'name' => $this->str($args['name'] ?? null),
        ];
        foreach (['description', 'move_roles', 'color'] as $key) {
            if (array_key_exists($key, $args)) {
                $data[$key] = $this->str($args[$key]);
            }
        }
        if (array_key_exists('position', $args)) {
            $data['position'] = $this->int($args['position']);
        }

        return $this->result($this->structure->createColumn($this->user, $data));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function stageUpdate(array $args): array
    {
        $stageId = $this->int($args['stage_id'] ?? null);
        if ($stageId <= 0) {
            return $this->content($this->lex('mxboard_err_column_not_found'), true);
        }

        $data = [];
        foreach (['name', 'description', 'move_roles', 'color'] as $key) {
            if (array_key_exists($key, $args)) {
                $data[$key] = $this->str($args[$key]);
            }
        }
        if (array_key_exists('position', $args)) {
            $data['position'] = $this->int($args['position']);
        }
        foreach (['is_initial', 'is_final', 'is_start'] as $key) {
            if (array_key_exists($key, $args)) {
                $data[$key] = !empty($args[$key]);
            }
        }

        return $this->result($this->structure->updateColumn($this->user, $stageId, $data));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function stageProjectId(array $args): ?int
    {
        if (array_key_exists('project_id', $args) && is_numeric($args['project_id'])) {
            return (int) $args['project_id'];
        }

        $project = $this->resolveProject($this->str($args['project'] ?? null));

        return $project ? (int) $project->get('id') : null;
    }

    /**
     * Аргументы структурных тулов приводим как есть (валидацию делает StructureService).
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function structureArgs(array $args): array
    {
        return $args;
    }

    /**
     * Общий рендер результата сервисов {success, message, object} в MCP-контент.
     *
     * @param array{success: bool, message: string, object: array<string, mixed>|null} $result
     *
     * @return array<string, mixed>
     */
    private function result(array $result): array
    {
        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        return $this->content(json_encode($result['object'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: 'OK');
    }

    private function resolveProject(string $key): ?MxBoardProject
    {
        return $this->tasks->resolveProject($key !== '' ? ['project' => $key] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function content(string $text, bool $isError = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $isError,
        ];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    private function lex(string $key): string
    {
        return $this->modx->lexicon($key) ?: $key;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
