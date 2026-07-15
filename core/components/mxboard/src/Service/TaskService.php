<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Helpers\Transitions;
use MxBoard\Helpers\Visibility;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardField;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardTask;
use MxBoard\Model\MxBoardTaskType;

/**
 * Вся логика жизненного цикла карточки в одном месте.
 *
 * Процессоры (менеджер), REST и MCP — тонкие обёртки над этим сервисом: правила
 * перехода, валидация и журнал не должны зависеть от того, каким каналом пришёл
 * запрос, иначе агент найдёт канал, где проверка слабее.
 */
class TaskService
{
    /** Длина заголовка — встроенный инвариант задачи. */
    private const TITLE_MAX = 250;

    public function __construct(private modX $modx)
    {
    }

    /**
     * Создать карточку. Попадает в initial-колонку проекта.
     *
     * Валидация (встроенные инварианты + поля типа): тип обязателен и «рабочий»,
     * title непустой ≤250, deadline > 0, обязательные поля типа заполнены. Если задан
     * parent_id — это подзадача: родитель обязан существовать, быть в том же проекте,
     * а создающий — его автором или исполнителем.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function create(modUser $user, array $data, string $channel = 'mgr'): array
    {
        $project = $this->resolveProject($data);
        if (!$project) {
            return $this->fail('mxboard_err_project_not_found');
        }

        // Подзадача: проверяем родителя и права до всего остального.
        $parentId = (int) ($data['parent_id'] ?? 0);
        $parent = null;
        if ($parentId > 0) {
            /** @var MxBoardTask|null $parent */
            $parent = $this->modx->getObject(MxBoardTask::class, $parentId);
            if (!$parent) {
                return $this->fail('mxboard_err_parent_not_found');
            }
            if ((int) $parent->get('project_id') !== (int) $project->get('id')) {
                return $this->fail('mxboard_err_parent_other_project');
            }
            $uid = (int) $user->get('id');
            $isParentParty = $uid === (int) $parent->get('author_id') || $uid === (int) $parent->get('assignee_id');
            if (!$isParentParty && !Transitions::isManager($this->modx, $user, $parent)) {
                return $this->fail('mxboard_err_subtask_denied');
            }
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->fail('mxboard_err_title_required');
        }
        if (mb_strlen($title) > self::TITLE_MAX) {
            return $this->fail('mxboard_err_title_too_long');
        }

        $deadline = $this->normalizeDeadline($data['deadline'] ?? $data['deadlineon'] ?? null);
        if ($deadline <= 0) {
            return $this->fail('mxboard_err_deadline_required');
        }

        [$type, $typeError] = $this->resolveType($data['type_id'] ?? $data['type'] ?? null, $project);
        if ($typeError !== null) {
            return $this->fail($typeError);
        }

        [$fields, $fieldError] = $this->validateFields($type, $this->decodeJson($data['fields'] ?? null) ?? []);
        if ($fieldError !== null) {
            return $this->fail($fieldError);
        }

        $column = $this->columnBy($project, ['is_initial' => true]);
        if (!$column) {
            return $this->fail('mxboard_err_no_initial_column');
        }

        $now = time();

        /** @var MxBoardTask $task */
        $task = $this->modx->newObject(MxBoardTask::class);
        $task->fromArray([
            'project_id' => (int) $project->get('id'),
            'parent_id' => $parentId,
            'type_id' => (int) $type->get('id'),
            'column_id' => (int) $column->get('id'),
            'title' => $title,
            'tor' => (string) ($data['tor'] ?? ''),
            'author_id' => (int) $user->get('id'),
            'assignee_id' => 0,
            'priority' => (int) ($data['priority'] ?? 0),
            'position' => $this->nextPosition((int) $column->get('id')),
            'deadlineon' => $deadline,
            'deadline_disputed' => 0,
            'deadline_proposed' => 0,
            'fields' => $fields,
            'meta' => $this->decodeJson($data['meta'] ?? null),
            'createdon' => $now,
            'updatedon' => $now,
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, 'create', '', (string) $column->get('key'), '', $channel);
        $this->fireEvent('mxbOnTaskCreate', $task, $user, ['channel' => $channel]);

        if ($parent) {
            // Отметка в журнале родителя: у него появилась (блокирующая) подзадача.
            $this->log($parent, $user, 'subtask_add', '', '', 'task#' . (int) $task->get('id'), $channel);
        }

        return $this->ok($task);
    }

    /**
     * Захватить свободную карточку из ready-колонки и перевести в работу.
     *
     * Захват атомарный: UPDATE ... WHERE assignee_id = 0 — гонку выигрывает ровно один
     * агент, второй получает 0 затронутых строк и внятный отказ. Проверка «свободна?»
     * отдельным SELECT'ом здесь не годится: между SELECT и UPDATE влезет второй агент.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function take(modUser $user, int $taskId, string $channel = 'mcp'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $project = $this->modx->getObject(MxBoardProject::class, (int) $task->get('project_id'));
        $current = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
        if (!$project || !$current) {
            return $this->fail('mxboard_err_task_not_found');
        }

        // Брать можно только из колонки «готово к работе».
        if (!(bool) $current->get('is_ready')) {
            return $this->fail('mxboard_err_not_ready');
        }

        $target = $this->nextColumnAfter($project, $current);
        if (!$target) {
            return $this->fail('mxboard_err_no_next_column');
        }

        $userId = (int) $user->get('id');

        $limit = (int) $this->modx->getOption('mxboard.wip_limit', null, 0);
        if ($limit > 0 && $this->inProgressCount($project, $userId) >= $limit) {
            return $this->fail('mxboard_err_wip_limit');
        }

        $table = $this->modx->getTableName(MxBoardTask::class);
        $now = time();

        $sql = "UPDATE {$table}
                   SET assignee_id = :uid, column_id = :col, startedon = :now, updatedon = :now
                 WHERE id = :id AND assignee_id = 0";

        $stmt = $this->modx->prepare($sql);
        $stmt->execute([
            ':uid' => $userId,
            ':col' => (int) $target->get('id'),
            ':now' => $now,
            ':id' => $taskId,
        ]);

        if ($stmt->rowCount() === 0) {
            // Ноль строк = карточку уже забрали между нашим чтением и апдейтом.
            return $this->fail('mxboard_err_already_taken');
        }

        /** @var MxBoardTask $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);

        $this->log($task, $user, 'take', (string) $current->get('key'), (string) $target->get('key'), '', $channel);
        $this->fireEvent('mxbOnTaskTake', $task, $user, ['channel' => $channel]);

        return $this->ok($task);
    }

    /**
     * Перевести карточку в колонку. Здесь же — единственная точка закрытия задачи.
     *
     * Инвариант: в финальную колонку нельзя, пока есть незавершённая подзадача. Это
     * структурная блокировка — она действует и на автора/менеджера, а не только на роли.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function move(modUser $user, int $taskId, string $columnKey, string $note = '', string $channel = 'mgr'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $project = $this->modx->getObject(MxBoardProject::class, (int) $task->get('project_id'));
        $current = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
        if (!$project) {
            return $this->fail('mxboard_err_project_not_found');
        }

        $target = $this->columnBy($project, ['key' => $columnKey]);
        if (!$target) {
            return $this->fail('mxboard_err_column_not_found');
        }

        if ($current && (int) $current->get('id') === (int) $target->get('id')) {
            return $this->ok($task);
        }

        $verdict = Transitions::can($this->modx, $user, $task, $target);
        if (!$verdict['allowed']) {
            return $this->fail($verdict['reason']);
        }

        $isFinal = (bool) $target->get('is_final');

        // Блокер: незавершённая подзадача не даёт закрыть родителя.
        if ($isFinal && $this->hasOpenSubtasks($taskId)) {
            return $this->fail('mxboard_err_open_subtasks');
        }

        $this->fireEvent('mxbOnBeforeTaskMove', $task, $user, [
            'channel' => $channel,
            'from' => $current ? (string) $current->get('key') : '',
            'to' => (string) $target->get('key'),
        ]);

        $now = time();

        $task->set('column_id', (int) $target->get('id'));
        $task->set('position', $this->nextPosition((int) $target->get('id')));
        $task->set('updatedon', $now);
        $task->set('closedon', $isFinal ? $now : 0);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $from = $current ? (string) $current->get('key') : '';
        $to = (string) $target->get('key');

        $this->log($task, $user, $isFinal ? 'close' : 'move', $from, $to, $note, $channel);
        $this->fireEvent('mxbOnTaskMove', $task, $user, ['channel' => $channel, 'from' => $from, 'to' => $to]);

        if ($isFinal) {
            $this->fireEvent('mxbOnTaskClose', $task, $user, ['channel' => $channel]);
        }

        return $this->ok($task);
    }

    /**
     * Отпустить карточку (вернуть в ready и снять исполнителя).
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function release(modUser $user, int $taskId, string $note = '', string $channel = 'mcp'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $userId = (int) $user->get('id');
        $isAssignee = $userId === (int) $task->get('assignee_id');

        if (!$isAssignee && !Transitions::isManager($this->modx, $user, $task)) {
            return $this->fail('mxboard_err_move_denied');
        }

        $project = $this->modx->getObject(MxBoardProject::class, (int) $task->get('project_id'));
        $current = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
        $ready = $project ? $this->columnBy($project, ['is_ready' => true]) : null;
        if (!$ready) {
            return $this->fail('mxboard_err_column_not_found');
        }

        $task->fromArray([
            'assignee_id' => 0,
            'column_id' => (int) $ready->get('id'),
            'startedon' => 0,
            'updatedon' => time(),
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log(
            $task,
            $user,
            'release',
            $current ? (string) $current->get('key') : '',
            (string) $ready->get('key'),
            $note,
            $channel
        );
        $this->fireEvent('mxbOnTaskRelease', $task, $user, ['channel' => $channel]);

        return $this->ok($task);
    }

    /**
     * Комментарий к карточке — как участники отчитываются о ходе работы.
     *
     * Комментировать вправе тот, кто видит задачу (автор/исполнитель/соисполнитель
     * подзадачи/менеджер) — та же логика, что и детальный просмотр.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function comment(modUser $user, int $taskId, string $content, string $channel = 'mcp'): array
    {
        $content = trim($content);
        if ($content === '') {
            return $this->fail('mxboard_err_comment_empty');
        }

        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        if (!Visibility::canView($this->modx, $user, $task)) {
            return $this->fail('mxboard_err_view_denied');
        }

        /** @var MxBoardComment $comment */
        $comment = $this->modx->newObject(MxBoardComment::class);
        $comment->fromArray([
            'task_id' => $taskId,
            'user_id' => (int) $user->get('id'),
            'content' => $content,
            'createdon' => time(),
        ]);

        if (!$comment->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, 'comment', '', '', mb_substr($content, 0, 255), $channel);
        $this->fireEvent('mxbOnTaskComment', $task, $user, ['channel' => $channel, 'comment' => $content]);

        return [
            'success' => true,
            'message' => '',
            'object' => $comment->toArray(),
        ];
    }

    /**
     * Оспорить дедлайн: исполнитель предлагает новую дату с причиной. Меняет дедлайн
     * не он — только автор через resolveDeadline.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function disputeDeadline(modUser $user, int $taskId, int $proposedDate, string $reason = '', string $channel = 'mcp'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        if ((int) $user->get('id') !== (int) $task->get('assignee_id')) {
            return $this->fail('mxboard_err_dispute_assignee_only');
        }

        if ($proposedDate <= 0) {
            return $this->fail('mxboard_err_deadline_required');
        }

        $task->fromArray([
            'deadline_disputed' => 1,
            'deadline_proposed' => $proposedDate,
            'updatedon' => time(),
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, 'deadline_dispute', '', '', mb_substr($reason, 0, 255), $channel);
        $this->fireEvent('mxbOnDeadlineDispute', $task, $user, ['channel' => $channel, 'proposed' => $proposedDate, 'reason' => $reason]);

        return $this->ok($task);
    }

    /**
     * Разрешить оспаривание дедлайна: автор/менеджер принимает (дедлайн = предложенный)
     * или отклоняет (остаётся прежний). В обоих случаях флаг и предложение сбрасываются.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function resolveDeadline(modUser $user, int $taskId, bool $accept, string $channel = 'mgr'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $isAuthor = (int) $user->get('id') === (int) $task->get('author_id');
        if (!$isAuthor && !Transitions::isManager($this->modx, $user, $task)) {
            return $this->fail('mxboard_err_author_only');
        }

        if (!(bool) $task->get('deadline_disputed')) {
            return $this->fail('mxboard_err_no_dispute');
        }

        $proposed = (int) $task->get('deadline_proposed');
        if ($accept && $proposed > 0) {
            $task->set('deadlineon', $proposed);
        }
        $task->fromArray([
            'deadline_disputed' => 0,
            'deadline_proposed' => 0,
            'updatedon' => time(),
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, $accept ? 'deadline_accepted' : 'deadline_rejected', '', '', '', $channel);
        $this->fireEvent('mxbOnDeadlineResolve', $task, $user, ['channel' => $channel, 'accepted' => $accept]);

        return $this->ok($task);
    }

    /**
     * Правка карточки автором/менеджером: заголовок, ToR, приоритет, дедлайн, тип, поля.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function update(modUser $user, int $taskId, array $data, string $channel = 'mgr'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $isAuthor = (int) $user->get('id') === (int) $task->get('author_id');
        if (!$isAuthor && !Transitions::isManager($this->modx, $user, $task)) {
            return $this->fail('mxboard_err_author_only');
        }

        $project = $this->modx->getObject(MxBoardProject::class, (int) $task->get('project_id'));
        if (!$project) {
            return $this->fail('mxboard_err_project_not_found');
        }

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                return $this->fail('mxboard_err_title_required');
            }
            if (mb_strlen($title) > self::TITLE_MAX) {
                return $this->fail('mxboard_err_title_too_long');
            }
            $task->set('title', $title);
        }

        if (array_key_exists('tor', $data)) {
            $task->set('tor', (string) $data['tor']);
        }

        if (array_key_exists('priority', $data)) {
            $task->set('priority', (int) $data['priority']);
        }

        if (array_key_exists('deadline', $data) || array_key_exists('deadlineon', $data)) {
            $deadline = $this->normalizeDeadline($data['deadline'] ?? $data['deadlineon']);
            if ($deadline <= 0) {
                return $this->fail('mxboard_err_deadline_required');
            }
            $task->set('deadlineon', $deadline);
        }

        // Смена типа (если пришла) валидируется; поля перепроверяются под действующий тип.
        $type = null;
        if (array_key_exists('type_id', $data) || array_key_exists('type', $data)) {
            [$type, $typeError] = $this->resolveType($data['type_id'] ?? $data['type'], $project);
            if ($typeError !== null) {
                return $this->fail($typeError);
            }
            $task->set('type_id', (int) $type->get('id'));
        }

        if (array_key_exists('fields', $data)) {
            if ($type === null) {
                /** @var MxBoardTaskType|null $type */
                $type = $this->modx->getObject(MxBoardTaskType::class, (int) $task->get('type_id'));
            }
            if (!$type) {
                return $this->fail('mxboard_err_type_not_found');
            }
            [$fields, $fieldError] = $this->validateFields($type, $this->decodeJson($data['fields']) ?? []);
            if ($fieldError !== null) {
                return $this->fail($fieldError);
            }
            $task->set('fields', $fields);
        }

        $task->set('updatedon', time());

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, 'update', '', '', '', $channel);
        $this->fireEvent('mxbOnTaskUpdate', $task, $user, ['channel' => $channel]);

        return $this->ok($task);
    }

    /**
     * Удалить карточку (автор/менеджер). Подзадачи не удаляются вместе с родителем —
     * они чужая работа: их открепляем (parent_id = 0), затем удаляем родителя
     * (его комментарии и журнал уйдут каскадом composite-связей).
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function delete(modUser $user, int $taskId, string $channel = 'mgr'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $isAuthor = (int) $user->get('id') === (int) $task->get('author_id');
        if (!$isAuthor && !Transitions::isManager($this->modx, $user, $task)) {
            return $this->fail('mxboard_err_author_only');
        }

        // Открепляем подзадачи, чтобы каскад composite не снёс чужие задачи.
        $childrenTable = $this->modx->getTableName(MxBoardTask::class);
        $stmt = $this->modx->prepare("UPDATE {$childrenTable} SET parent_id = 0 WHERE parent_id = :id");
        $stmt->execute([':id' => $taskId]);

        if (!$task->remove()) {
            return $this->fail('mxboard_err_save');
        }

        $this->fireEvent('mxbOnTaskDelete', $task, $user, ['channel' => $channel]);

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Проект по данным запроса: project_id (int) → project (key) → настройка по умолчанию.
     *
     * @param array<string, mixed> $data
     */
    public function resolveProject(array $data): ?MxBoardProject
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        if ($projectId > 0) {
            /** @var MxBoardProject|null $project */
            $project = $this->modx->getObject(MxBoardProject::class, ['id' => $projectId, 'active' => true]);

            return $project;
        }

        $key = trim((string) ($data['project'] ?? ''));
        $key = $key !== '' ? $key : (string) $this->modx->getOption('mxboard.default_project', null, 'default');

        /** @var MxBoardProject|null $project */
        $project = $this->modx->getObject(MxBoardProject::class, ['key' => $key, 'active' => true]);

        return $project;
    }

    /**
     * Колонка проекта по произвольному критерию.
     *
     * @param array<string, mixed> $criteria
     */
    public function columnBy(MxBoardProject $project, array $criteria): ?MxBoardColumn
    {
        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(
            MxBoardColumn::class,
            array_merge(['project_id' => (int) $project->get('id')], $criteria)
        );

        return $column;
    }

    /**
     * Разрешить и провалидировать тип задачи для проекта.
     *
     * Тип обязателен, принадлежит отделу проекта, активен и «рабочий» (имеет ≥1 поле).
     *
     * @param mixed $typeRef id (int) или ключ типа (string)
     *
     * @return array{0: MxBoardTaskType|null, 1: string|null} [тип, ключ-ошибки]
     */
    private function resolveType(mixed $typeRef, MxBoardProject $project): array
    {
        if ($typeRef === null || $typeRef === '' || $typeRef === 0 || $typeRef === '0') {
            return [null, 'mxboard_err_type_required'];
        }

        $departmentId = (int) $project->get('department_id');
        $criteria = is_numeric($typeRef)
            ? ['id' => (int) $typeRef, 'department_id' => $departmentId, 'active' => true]
            : ['key' => (string) $typeRef, 'department_id' => $departmentId, 'active' => true];

        /** @var MxBoardTaskType|null $type */
        $type = $this->modx->getObject(MxBoardTaskType::class, $criteria);
        if (!$type) {
            return [null, 'mxboard_err_type_not_found'];
        }

        // «Рабочий» тип обязан иметь хотя бы одно своё поле.
        $fieldCount = $this->modx->getCount(MxBoardField::class, ['task_type_id' => (int) $type->get('id')]);
        if ($fieldCount < 1) {
            return [null, 'mxboard_err_type_no_fields'];
        }

        return [$type, null];
    }

    /**
     * Проверить и нормализовать значения полей типа.
     *
     * Обязательные поля должны быть заполнены; в результат кладём только известные ключи.
     *
     * @param array<string, mixed> $input
     *
     * @return array{0: array<string, mixed>, 1: string|null} [нормализованные поля, ключ-ошибки]
     */
    private function validateFields(MxBoardTaskType $type, array $input): array
    {
        $out = [];

        $c = $this->modx->newQuery(MxBoardField::class);
        $c->where(['task_type_id' => (int) $type->get('id')]);
        $c->sortby('position', 'ASC');
        /** @var MxBoardField[] $fields */
        $fields = $this->modx->getCollection(MxBoardField::class, $c);

        foreach ($fields as $field) {
            $key = (string) $field->get('key');
            $value = $input[$key] ?? null;
            $filled = $value !== null && (!is_string($value) || trim($value) !== '');

            if ((bool) $field->get('required') && !$filled) {
                return [[], 'mxboard_err_field_required'];
            }

            if ($filled) {
                $out[$key] = $value;
            }
        }

        return [$out, null];
    }

    /** Есть ли у задачи незавершённые подзадачи (closedon = 0 = не в финальной стадии). */
    private function hasOpenSubtasks(int $taskId): bool
    {
        return (int) $this->modx->getCount(MxBoardTask::class, [
            'parent_id' => $taskId,
            'closedon' => 0,
        ]) > 0;
    }

    /** Следующая по порядку колонка (куда карточка едет при захвате). */
    private function nextColumnAfter(MxBoardProject $project, MxBoardColumn $current): ?MxBoardColumn
    {
        $c = $this->modx->newQuery(MxBoardColumn::class);
        $c->where([
            'project_id' => (int) $project->get('id'),
            'position:>' => (int) $current->get('position'),
        ]);
        $c->sortby('position', 'ASC');
        $c->limit(1);

        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(MxBoardColumn::class, $c);

        return $column;
    }

    /** Сколько карточек уже в работе у пользователя (для wip_limit). */
    private function inProgressCount(MxBoardProject $project, int $userId): int
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->innerJoin(MxBoardColumn::class, 'Column');
        $c->where([
            'MxBoardTask.project_id' => (int) $project->get('id'),
            'MxBoardTask.assignee_id' => $userId,
            'Column.is_final' => false,
            'MxBoardTask.startedon:>' => 0,
        ]);

        return (int) $this->modx->getCount(MxBoardTask::class, $c);
    }

    private function nextPosition(int $columnId): int
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->where(['column_id' => $columnId]);

        return (int) $this->modx->getCount(MxBoardTask::class, $c);
    }

    /**
     * Разобрать дедлайн из любого канала: unix-число как есть, строку-дату — через strtotime.
     *
     * Централизовано ЗДЕСЬ, а не в фасадах (MCP/REST): иначе REST-путь принимал бы
     * "2026-12-31" и обрезал его в (int) до 2026 (эпоха 1970-х) — задача создавалась бы
     * с битым дедлайном, но валидацию (>0) проходила.
     */
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
     * Декодировать JSON-вход (поля/мета): массив как есть, строку — распарсить.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /** Журнал переходов: пишем ВСЕГДА и из всех каналов — это единственная защита от вранья в статусах. */
    private function log(
        MxBoardTask $task,
        modUser $user,
        string $action,
        string $from,
        string $to,
        string $note,
        string $channel
    ): void {
        /** @var MxBoardLog $log */
        $log = $this->modx->newObject(MxBoardLog::class);
        $log->fromArray([
            'task_id' => (int) $task->get('id'),
            'user_id' => (int) $user->get('id'),
            'action' => $action,
            'from_column' => $from,
            'to_column' => $to,
            'note' => $note,
            'channel' => $channel,
            'createdon' => time(),
        ]);
        $log->save();
    }

    /**
     * Событие MODX — точка интеграции (Jarvis, трекеры). Ядро о них не знает.
     *
     * @param array<string, mixed> $extra
     */
    private function fireEvent(string $event, MxBoardTask $task, modUser $user, array $extra = []): void
    {
        try {
            $this->modx->invokeEvent($event, array_merge([
                'task_id' => (int) $task->get('id'),
                'task' => $task->toArray(),
                'user_id' => (int) $user->get('id'),
            ], $extra));
        } catch (\Throwable $e) {
            // Кривой плагин интегратора не должен ронять работу доски.
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[mxBoard] Событие {$event}: " . $e->getMessage());
        }
    }

    /** @return array{success: bool, message: string, object: array<string, mixed>|null} */
    private function ok(MxBoardTask $task): array
    {
        return ['success' => true, 'message' => '', 'object' => $task->toArray()];
    }

    /** @return array{success: bool, message: string, object: null} */
    private function fail(string $lexiconKey): array
    {
        return [
            'success' => false,
            'message' => $this->modx->lexicon($lexiconKey) ?: $lexiconKey,
            'object' => null,
        ];
    }
}
