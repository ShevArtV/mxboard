<?php

declare(strict_types=1);

namespace MxBoard\Mcp;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\TaskService;

/**
 * MCP-сервер mxBoard: JSON-RPC 2.0 поверх Streamable HTTP.
 *
 * Транспорт (заголовки, HTTP-коды, токен) — в assets/components/mxboard/mcp.php.
 * Здесь только протокол и инструменты: класс ничего не печатает и не завершает
 * процесс, handle() возвращает массив ответа либо null для нотификации.
 *
 * Сервер stateless: сессии MCP (Mcp-Session-Id) не заводим — агенту нужен голый
 * запрос-ответ, а состояние доски и так в БД.
 *
 * Правила доски здесь НЕ дублируются: всё идёт через TaskService с каналом 'mcp'
 * от имени пользователя, которому принадлежит токен. Отсюда и модель прав: агент
 * физически не может закрыть чужую задачу — не потому, что мы это проверяем, а
 * потому, что это запрещено его пользователю.
 */
final class Server
{
    /** Ревизия спеки MCP, которую заявляем в initialize. */
    public const PROTOCOL_VERSION = '2025-06-18';

    private const SERVER_NAME = 'mxboard';
    private const SERVER_VERSION = '1.0.0';

    /** Канал для журнала переходов: отличает действия агента от менеджера и REST. */
    private const CHANNEL = 'mcp';

    /** Потолки выдачи board_list: агенту нужен обзор доски, а не дамп базы в контекст. */
    private const MAX_TASKS = 300;
    private const MAX_PER_COLUMN = 30;

    private TaskService $tasks;

    public function __construct(private modX $modx, private modUser $user)
    {
        $this->tasks = new TaskService($modx);
    }

    /**
     * Разобрать и выполнить один JSON-RPC запрос.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>|null null — на нотификацию отвечать нечем (по JSON-RPC)
     */
    public function handle(array $request): ?array
    {
        // Нотификация — запрос без id (notifications/initialized и прочие). Ответа быть не должно,
        // даже на ошибку: клиент его не ждёт и не сможет сопоставить.
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
            // Кривой вызов инструмента — это протокольная ошибка, а не результат работы.
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
            // tools: {} — пустой объект, а не список: у нас нет ни подписок, ни listChanged.
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            'instructions' => 'Канбан-доска mxBoard. Порядок работы: board_list — посмотреть, '
                . 'task_take — взять свободную карточку, task_comment — отчитаться о ходе, '
                . 'task_move — перевести в следующую колонку. Все действия идут от вашего '
                . 'пользователя MODX и пишутся в журнал доски.',
        ];
    }

    /**
     * Описания инструментов. Их схемы висят в контексте агента на каждом ходу,
     * поэтому набор намеренно узкий (5 штук), а описания — короткие.
     *
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'board_list',
                'description' => 'Что сейчас на доске: колонки и карточки в них. Без аргументов — доска по умолчанию целиком.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'board' => ['type' => 'string', 'description' => 'Ключ доски. По умолчанию — доска из настроек.'],
                        'column' => ['type' => 'string', 'description' => 'Ключ колонки: показать только её.'],
                        'mine' => ['type' => 'boolean', 'description' => 'Только карточки, взятые вами.'],
                    ],
                ],
            ],
            [
                'name' => 'task_take',
                'description' => 'Взять свободную карточку в работу. Захват атомарный: если её уже забрал другой агент, вернётся ошибка.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'ID карточки из board_list.'],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'task_move',
                'description' => 'Перевести карточку в колонку по её ключу. Если правила доски не пускают (например, закрывать вправе только автор), вернётся ошибка с причиной.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'ID карточки.'],
                        'column' => ['type' => 'string', 'description' => 'Ключ колонки назначения.'],
                        'note' => ['type' => 'string', 'description' => 'Пояснение к переходу — попадёт в журнал.'],
                    ],
                    'required' => ['task_id', 'column'],
                ],
            ],
            [
                'name' => 'task_comment',
                'description' => 'Комментарий к карточке: так агент отчитывается о ходе работы.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer', 'description' => 'ID карточки.'],
                        'content' => ['type' => 'string', 'description' => 'Текст (markdown).'],
                    ],
                    'required' => ['task_id', 'content'],
                ],
            ],
            [
                'name' => 'task_create',
                'description' => 'Поставить новую задачу. Попадает в стартовую колонку доски; автором станете вы.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Заголовок.'],
                        'tor' => ['type' => 'string', 'description' => 'Постановка (ToR) в markdown.'],
                        'board' => ['type' => 'string', 'description' => 'Ключ доски. По умолчанию — доска из настроек.'],
                        'priority' => ['type' => 'integer', 'description' => 'Приоритет, больше — важнее. По умолчанию 0.'],
                        'meta' => ['type' => 'object', 'description' => 'Произвольные метаданные интегратора (cwd, движок, топик). Ядро их не интерпретирует.'],
                    ],
                    'required' => ['title'],
                ],
            ],
        ];
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
            'board_list' => $this->boardList($args),
            'task_take' => $this->taskTake($args),
            'task_move' => $this->taskMove($args),
            'task_comment' => $this->taskComment($args),
            'task_create' => $this->taskCreate($args),
            default => throw new \InvalidArgumentException('Unknown tool: ' . $name),
        };
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function boardList(array $args): array
    {
        $boardKey = $this->str($args['board'] ?? null);
        $board = $this->tasks->resolveBoard($boardKey !== '' ? $boardKey : null);
        if (!$board) {
            return $this->content($this->lex('mxboard_err_board_not_found'), true);
        }

        $columnKey = $this->str($args['column'] ?? null);
        $mine = !empty($args['mine']);
        $userId = (int) $this->user->get('id');
        $boardId = (int) $board->get('id');

        $q = $this->modx->newQuery(MxBoardColumn::class);
        $q->where($columnKey !== '' ? ['board_id' => $boardId, 'key' => $columnKey] : ['board_id' => $boardId]);
        $q->sortby('rank', 'ASC');

        /** @var MxBoardColumn[] $columns */
        $columns = $this->modx->getCollection(MxBoardColumn::class, $q);
        if (!$columns) {
            return $this->content($this->lex('mxboard_err_column_not_found'), true);
        }

        $rows = $this->fetchTasks($boardId, $columnKey, $mine ? $userId : 0);

        /** @var array<string, list<array<string, mixed>>> $byColumn */
        $byColumn = [];
        foreach ($rows as $row) {
            $byColumn[(string) $row['column_key']][] = $row;
        }

        $out = [];
        $out[] = 'Доска: ' . $board->get('name') . ' [' . $board->get('key') . ']';
        $out[] = 'Вы: ' . $this->user->get('username') . ' (#' . $userId . ')'
            . ($mine ? ' — показаны только ваши карточки' : '');
        $out[] = '';

        foreach ($columns as $column) {
            $key = (string) $column->get('key');
            $list = $byColumn[$key] ?? [];

            $flags = [];
            if ((bool) $column->get('is_ready')) {
                $flags[] = 'можно брать: task_take';
            }
            if ((bool) $column->get('is_final')) {
                $flags[] = 'финальная';
            }

            $out[] = '[' . $key . '] ' . $column->get('name') . ' — ' . count($list)
                . ($flags ? ' (' . implode(', ', $flags) . ')' : '');

            if (!$list) {
                $out[] = '  пусто';
                $out[] = '';
                continue;
            }

            foreach (array_slice($list, 0, self::MAX_PER_COLUMN) as $row) {
                $out[] = '  ' . $this->taskLine($row, $userId);
            }
            $hidden = count($list) - self::MAX_PER_COLUMN;
            if ($hidden > 0) {
                $out[] = '  … и ещё ' . $hidden;
            }
            $out[] = '';
        }

        if (!$rows) {
            $out[] = $mine ? 'У вас нет карточек на этой доске.' : 'Карточек нет.';
        }

        return $this->content(rtrim(implode("\n", $out)));
    }

    /**
     * Карточки доски одним запросом: имена автора и исполнителя тянем джойном,
     * иначе на каждую карточку ушло бы по два getObject (N+1).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchTasks(int $boardId, string $columnKey, int $assigneeId): array
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->innerJoin(MxBoardColumn::class, 'Column');
        $c->leftJoin(modUser::class, 'Author');
        $c->leftJoin(modUser::class, 'Assignee');

        $where = ['MxBoardTask.board_id' => $boardId];
        if ($columnKey !== '') {
            $where['Column.key'] = $columnKey;
        }
        if ($assigneeId > 0) {
            $where['MxBoardTask.assignee_id'] = $assigneeId;
        }
        $c->where($where);

        $c->select([
            'MxBoardTask.id',
            'MxBoardTask.title',
            'MxBoardTask.priority',
            'MxBoardTask.assignee_id',
            'column_key' => 'Column.key',
            'author' => 'Author.username',
            'assignee' => 'Assignee.username',
        ]);

        $c->sortby('Column.rank', 'ASC');
        $c->sortby('MxBoardTask.priority', 'DESC');
        $c->sortby('MxBoardTask.rank', 'ASC');
        $c->limit(self::MAX_TASKS);

        $c->prepare();
        if (!$c->stmt || !$c->stmt->execute()) {
            return [];
        }

        return (array) $c->stmt->fetchAll(\PDO::FETCH_ASSOC);
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

        return '#' . (int) $row['id']
            . ($priority > 0 ? ' · p' . $priority : '')
            . ' · ' . (string) $row['title']
            . ' · автор ' . ((string) ($row['author'] ?? '') ?: '—')
            . ' · ' . $who;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskTake(array $args): array
    {
        $taskId = $this->int($args['task_id'] ?? null);
        if ($taskId <= 0) {
            return $this->content($this->lex('mxboard_err_task_id_required'), true);
        }

        $result = $this->tasks->take($this->user, $taskId, self::CHANNEL);
        if (!$result['success']) {
            // Гонку за карточку проиграли (или колонка не та) — агент должен увидеть причину
            // и взять другую задачу, а не считать, что вызов прошёл.
            return $this->content($result['message'], true);
        }

        $task = (array) $result['object'];

        return $this->content(
            'Карточка #' . $taskId . ' «' . (string) $task['title'] . '» взята в работу. '
            . 'Колонка: ' . $this->columnKeyOf($task) . '. '
            . 'Отчитывайтесь через task_comment, по готовности — task_move.'
        );
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function taskMove(array $args): array
    {
        $taskId = $this->int($args['task_id'] ?? null);
        if ($taskId <= 0) {
            return $this->content($this->lex('mxboard_err_task_id_required'), true);
        }

        $column = $this->str($args['column'] ?? null);
        if ($column === '') {
            return $this->content($this->lex('mxboard_err_column_required'), true);
        }

        $result = $this->tasks->move($this->user, $taskId, $column, $this->str($args['note'] ?? null), self::CHANNEL);
        if (!$result['success']) {
            // Отказ правил доски (не автор, самоаттестация, нет прав) отдаём текстом как есть:
            // подавить его — значит соврать агенту, что переход состоялся.
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
        $taskId = $this->int($args['task_id'] ?? null);
        if ($taskId <= 0) {
            return $this->content($this->lex('mxboard_err_task_id_required'), true);
        }

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
    private function taskCreate(array $args): array
    {
        $board = $this->str($args['board'] ?? null);

        $result = $this->tasks->create($this->user, [
            'title' => $this->str($args['title'] ?? null),
            'tor' => $this->str($args['tor'] ?? null),
            'board' => $board !== '' ? $board : null,
            'priority' => $this->int($args['priority'] ?? null),
            'meta' => isset($args['meta']) && is_array($args['meta']) ? $args['meta'] : null,
        ], self::CHANNEL);

        if (!$result['success']) {
            return $this->content($result['message'], true);
        }

        $task = (array) $result['object'];

        return $this->content(
            'Карточка #' . (int) $task['id'] . ' «' . (string) $task['title'] . '» создана. '
            . 'Колонка: ' . $this->columnKeyOf($task) . '.'
        );
    }

    /** @param array<string, mixed> $task */
    private function columnKeyOf(array $task): string
    {
        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(MxBoardColumn::class, (int) ($task['column_id'] ?? 0));

        return $column ? (string) $column->get('key') : '—';
    }

    /**
     * Результат tools/call по спеке MCP. Ошибка бизнес-логики — это isError: true,
     * а не JSON-RPC error: агент должен прочитать причину и среагировать, а не упасть.
     *
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
