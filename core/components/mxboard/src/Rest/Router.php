<?php

declare(strict_types=1);

namespace MxBoard\Rest;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Service\BoardQuery;
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

    public function __construct(private modX $modx, private modUser $user)
    {
        $this->tasks = new TaskService($modx);
        $this->structure = new StructureService($modx);
        $this->query = new BoardQuery($modx);
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

        $taskId = (int) ($seg[1] ?? 0);
        if ($taskId <= 0) {
            return $this->fail('mxboard_err_task_id_required', 400);
        }
        $action = $seg[2] ?? '';

        // GET /tasks/{id}
        if ($method === 'GET' && $action === '') {
            /** @var MxBoardTask|null $task */
            $task = $this->modx->getObject(MxBoardTask::class, $taskId);
            if (!$task) {
                return $this->fail('mxboard_err_task_not_found', 404);
            }
            $detail = $this->query->taskDetail($this->user, $task);
            if ($detail === null) {
                return $this->fail('mxboard_err_view_denied', 403);
            }

            return $this->ok($detail);
        }

        // PATCH /tasks/{id}
        if ($method === 'PATCH' && $action === '') {
            return $this->result($this->tasks->update($this->user, $taskId, $body, self::CHANNEL));
        }

        // DELETE /tasks/{id}
        if ($method === 'DELETE' && $action === '') {
            return $this->result($this->tasks->delete($this->user, $taskId, self::CHANNEL));
        }

        // POST /tasks/{id}/{action}
        if ($method === 'POST') {
            return match ($action) {
                'take' => $this->result($this->tasks->take($this->user, $taskId, self::CHANNEL)),
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
                    $this->deadline($body['proposed_date'] ?? null),
                    (string) ($body['reason'] ?? ''),
                    self::CHANNEL
                )),
                'resolve-deadline' => $this->result($this->tasks->resolveDeadline($this->user, $taskId, !empty($body['accept']), self::CHANNEL)),
                default => $this->fail('mxboard_err_route_not_found', 404),
            };
        }

        return $this->fail('mxboard_err_route_not_found', 404);
    }

    private function resolveProject(string $key): ?MxBoardProject
    {
        return $this->tasks->resolveProject($key !== '' ? ['project' => $key] : []);
    }

    /** Дедлайн: unix-число как есть, строку — через strtotime. */
    private function deadline(mixed $value): int
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
