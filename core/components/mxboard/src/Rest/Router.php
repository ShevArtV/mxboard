<?php

declare(strict_types=1);

namespace MxBoard\Rest;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\BoardQuery;
use MxBoard\Service\QueueService;
use MxBoard\Service\StructureService;
use MxBoard\Service\TaskService;

/**
 * REST-маршрутизация mxBoard: HTTP-метод + путь → метод сервиса.
 *
 * Транспорт (бутстрап, авторизация, HTTP-заголовки) — в assets/components/mxboard/rest.php.
 * Здесь только разбор маршрута и вызов ОБЩИХ сервисов (TaskService/StructureService/BoardQuery),
 * тех же, что у MCP. Канал журнала — 'api'. Расхождению прав между REST и MCP взяться неоткуда:
 * оба — фасады над одним сервисом.
 */
final class Router
{
    private const CHANNEL = 'api';

    private TaskService $tasks;
    private StructureService $structure;
    private BoardQuery $query;
    private QueueService $queues;

    public function __construct(private modX $modx, private modUser $user)
    {
        $this->tasks = new TaskService($modx);
        $this->structure = new StructureService($modx);
        $this->query = new BoardQuery($modx);
        $this->queues = new QueueService($modx);
    }

    /**
     * @param list<string>         $seg   сегменты пути (без пустых)
     * @param array<string, mixed> $query query-параметры
     * @param array<string, mixed> $body  разобранное JSON-тело
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function dispatch(string $method, array $seg, array $query, array $body): array
    {
        $method = strtoupper($method);
        $resource = $seg[0] ?? '';

        // GET /projects
        if ($method === 'GET' && $resource === 'projects' && !isset($seg[1])) {
            return $this->ok($this->query->projects());
        }

        // GET /events?since=<log_id>&limit=<n> — инкрементальная лента журнала для внешних
        // интеграций (напр. локальный Jarvis-поллер): один курсор по id, задачи обогащены.
        // Только менеджеру отдела — это глобальный поток по всем задачам, а не «свои».
        if ($method === 'GET' && $resource === 'events' && !isset($seg[1])) {
            if (!\MxBoard\Helpers\Transitions::isAnyDepartmentManager($this->modx, $this->user)) {
                return $this->fail('mxboard_err_move_denied', 403);
            }

            return $this->ok($this->query->events(
                (int) ($query['since'] ?? 0),
                (int) ($query['limit'] ?? 100)
            ));
        }

        // GET /board?project=key
        if ($method === 'GET' && $resource === 'board' && !isset($seg[1])) {
            $project = $this->resolveProject((string) ($query['project'] ?? ''));
            if (!$project) {
                return $this->fail('mxboard_err_project_not_found', 404);
            }

            return $this->ok($this->query->board($this->user, $project, [
                'column' => (string) ($query['column'] ?? ''),
                'mine' => !empty($query['mine']),
                'author_id' => (int) ($query['author_id'] ?? 0),
                'assignee_id' => (int) ($query['assignee_id'] ?? 0),
            ]));
        }

        // GET /types/{key}/schema?project=key
        if ($method === 'GET' && $resource === 'types' && ($seg[2] ?? '') === 'schema' && isset($seg[1])) {
            $project = $this->resolveProject((string) ($query['project'] ?? ''));
            if (!$project) {
                return $this->fail('mxboard_err_project_not_found', 404);
            }
            $schema = $this->query->typeSchema($project, (string) $seg[1]);
            if ($schema === null) {
                return $this->fail('mxboard_err_type_not_found', 404);
            }

            return $this->ok($schema);
        }

        // GET /departments  и  /departments/{id}/{users|types}
        if ($method === 'GET' && $resource === 'departments') {
            if (!isset($seg[1])) {
                return $this->ok($this->query->departments());
            }
            $departmentId = (int) $seg[1];
            return match ($seg[2] ?? '') {
                'users' => $this->ok($this->query->departmentUsers($departmentId)),
                'types' => $this->ok($this->query->types($departmentId)),
                default => $this->fail('mxboard_err_route_not_found', 404),
            };
        }

        // GET /projects/{id}/columns — стадии проекта
        if ($method === 'GET' && $resource === 'projects' && isset($seg[1]) && ($seg[2] ?? '') === 'columns') {
            return $this->ok($this->query->columns((int) $seg[1]));
        }
        // POST /projects/{id}/columns — создать стадию проекта
        if ($method === 'POST' && $resource === 'projects' && isset($seg[1]) && ($seg[2] ?? '') === 'columns') {
            $body['project_id'] = (int) $seg[1];

            return $this->result($this->structure->createColumn($this->user, $body), 201);
        }
        // PATCH /columns/{id} — правка стадии
        if ($method === 'PATCH' && $resource === 'columns' && isset($seg[1]) && !isset($seg[2])) {
            return $this->result($this->structure->updateColumn($this->user, (int) $seg[1], $body));
        }

        // GET /projects/{id}/queues[?with_tasks=1] — очереди проекта
        if ($method === 'GET' && $resource === 'projects' && isset($seg[1]) && ($seg[2] ?? '') === 'queues') {
            return $this->ok($this->queues->queues((int) $seg[1], !empty($query['with_tasks'])));
        }
        // POST /projects/{id}/queues — создать очередь
        if ($method === 'POST' && $resource === 'projects' && isset($seg[1]) && ($seg[2] ?? '') === 'queues') {
            $body['project_id'] = (int) $seg[1];

            return $this->result($this->queues->create($this->user, $body), 201);
        }
        // PATCH|DELETE /queues/{id}  и  POST /queues/{id}/reorder
        if ($resource === 'queues' && isset($seg[1])) {
            $queueId = (int) $seg[1];
            if ($method === 'PATCH' && !isset($seg[2])) {
                return $this->result($this->queues->update($this->user, $queueId, $body));
            }
            if ($method === 'DELETE' && !isset($seg[2])) {
                return $this->result($this->queues->remove($this->user, $queueId));
            }
            if ($method === 'POST' && ($seg[2] ?? '') === 'reorder') {
                $order = $body['order'] ?? [];

                return $this->result($this->queues->reorder($this->user, $queueId, is_array($order) ? $order : []));
            }
        }

        // /tasks ...
        if ($resource === 'tasks') {
            return $this->tasksRoute($method, $seg, $body);
        }

        // Структура (менеджер): POST /types, /projects, /departments
        if ($method === 'POST' && $resource === 'types' && !isset($seg[1])) {
            return $this->result($this->structure->createType($this->user, $body), 201);
        }
        if ($method === 'POST' && $resource === 'projects' && !isset($seg[1])) {
            return $this->result($this->structure->createProject($this->user, $body), 201);
        }
        if ($method === 'POST' && $resource === 'departments' && !isset($seg[1])) {
            return $this->result($this->structure->registerDepartment($this->user, $body), 201);
        }

        return $this->fail('mxboard_err_route_not_found', 404);
    }

    /**
     * @param list<string>         $seg
     * @param array<string, mixed> $body
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function tasksRoute(string $method, array $seg, array $body): array
    {
        // POST /tasks — создать
        if ($method === 'POST' && !isset($seg[1])) {
            return $this->result($this->tasks->create($this->user, $body, self::CHANNEL), 201);
        }

        // Адрес задачи в пути — id (число) или num (напр. 2607-15).
        $task = $this->tasks->resolveTaskRef((string) ($seg[1] ?? ''));
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found', 404);
        }
        $taskId = (int) $task->get('id');
        $action = $seg[2] ?? '';

        // GET /tasks/{id}
        if ($method === 'GET' && $action === '') {
            $detail = $this->query->taskDetail($this->user, $task);
            if ($detail === null) {
                return $this->fail('mxboard_err_view_denied', 403);
            }

            return $this->ok($detail);
        }

        // PATCH /tasks/{id}/comments/{cid}
        if ($method === 'PATCH' && $action === 'comments' && isset($seg[3])) {
            return $this->result($this->tasks->updateComment(
                $this->user,
                (int) $seg[3],
                (string) ($body['content'] ?? ''),
                self::CHANNEL
            ));
        }

        // DELETE /tasks/{id}/comments/{cid}
        if ($method === 'DELETE' && $action === 'comments' && isset($seg[3])) {
            return $this->result($this->tasks->deleteComment($this->user, (int) $seg[3], self::CHANNEL));
        }

        // PATCH /tasks/{id}
        if ($method === 'PATCH' && $action === '') {
            return $this->result($this->tasks->update($this->user, $taskId, $body, self::CHANNEL));
        }

        // DELETE /tasks/{id}
        if ($method === 'DELETE' && $action === '') {
            return $this->result($this->tasks->delete($this->user, $taskId, self::CHANNEL));
        }

        // DELETE /tasks/{id}/queue — вынуть задачу из очереди
        if ($method === 'DELETE' && $action === 'queue') {
            return $this->result($this->queues->removeTask($this->user, $taskId));
        }

        // POST /tasks/{id}/{action}
        if ($method === 'POST') {
            return match ($action) {
                'move' => $this->result($this->tasks->move(
                    $this->user,
                    $taskId,
                    (string) ($body['column'] ?? ''),
                    (string) ($body['note'] ?? ''),
                    self::CHANNEL
                )),
                'comment' => $this->result($this->tasks->comment($this->user, $taskId, (string) ($body['content'] ?? ''), self::CHANNEL)),
                'dispute-deadline' => $this->result($this->tasks->disputeDeadline(
                    $this->user,
                    $taskId,
                    $this->normalizeDeadline($body['proposed_date'] ?? null),
                    (string) ($body['reason'] ?? ''),
                    self::CHANNEL
                )),
                'resolve-deadline' => $this->result($this->tasks->resolveDeadline($this->user, $taskId, !empty($body['accept']), self::CHANNEL)),
                'dispute-plan' => $this->result($this->tasks->disputePlan(
                    $this->user,
                    $taskId,
                    is_numeric($body['proposed_hours'] ?? null) ? (int) round((float) $body['proposed_hours']) : 0,
                    (string) ($body['reason'] ?? ''),
                    self::CHANNEL
                )),
                'resolve-plan' => $this->result($this->tasks->resolvePlan($this->user, $taskId, !empty($body['accept']), self::CHANNEL)),
                // queue без тела — «в единственную очередь проекта».
                'queue' => $this->result($this->queues->addTask($this->user, $taskId, (int) ($body['queue_id'] ?? 0))),
                'promote' => $this->result($this->queues->promote($this->user, $taskId)),
                default => $this->fail('mxboard_err_route_not_found', 404),
            };
        }

        return $this->fail('mxboard_err_route_not_found', 404);
    }

    private function resolveProject(string $key): ?MxBoardProject
    {
        return $this->tasks->resolveProject($key !== '' ? ['project' => $key] : []);
    }

    private function normalizeDeadline(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return (int) (strtotime(trim($value)) ?: 0);
        }

        return 0;
    }

    /**
     * @param mixed $data
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function ok(mixed $data, int $status = 200): array
    {
        return ['status' => $status, 'body' => ['success' => true, 'message' => '', 'data' => $data]];
    }

    /**
     * Результат сервиса {success, message, object} → HTTP-ответ.
     *
     * @param array{success: bool, message: string, object: mixed} $result
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function result(array $result, int $successStatus = 200): array
    {
        if (!$result['success']) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => (string) $result['message'], 'data' => null]];
        }

        return ['status' => $successStatus, 'body' => ['success' => true, 'message' => (string) $result['message'], 'data' => $result['object']]];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function fail(string $lexiconKey, int $status): array
    {
        return [
            'status' => $status,
            'body' => ['success' => false, 'message' => $this->modx->lexicon($lexiconKey) ?: $lexiconKey, 'data' => null],
        ];
    }
}
