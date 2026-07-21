<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Helpers\Columns;
use MxBoard\Helpers\TaskNum;
use MxBoard\Helpers\Transitions;
use MxBoard\Helpers\Visibility;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardCounter;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardDepartment;
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
     * title непустой ≤250, deadline > 0, обязательные поля типа заполнены. Плановое
     * время (plan_hours) — необязательное: 0/пусто значит «не оценивали». Если задан
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

        // ИИ-проверка полноты постановки (если включена у типа). Вердикт всегда идёт в журнал;
        // при «неполной» в strict (или soft без ai_override) — блок и возврат вердикта фронту.
        $aiVerdict = null;
        if ((bool) $type->get('ai_check')) {
            $verdict = (new AiReviewer($this->modx))->review(
                ['title' => $title, 'tor' => (string) ($data['tor'] ?? ''), 'fields' => $fields],
                $type,
                $this->fieldDefs($type)
            );
            if ($verdict !== null) {
                $mode = strtolower((string) $this->modx->getOption('mxboard.ai_check_mode', null, 'strict'));
                $override = !empty($data['ai_override']);
                if (!$verdict['complete'] && !($mode === 'soft' && $override)) {
                    // Задачи ещё нет — вердикт отказа кладём в журнал с task_id=0 (аудит).
                    $this->logAiCheck(0, $user, $verdict, $title, $channel);

                    return $this->aiReject($verdict, $mode);
                }
                // Проходит (полная) или soft+override: вердикт поедет в задачу и журнал ниже.
                $verdict['overridden'] = !$verdict['complete'];
                $aiVerdict = $verdict;
            }
            // $verdict === null — провайдер недоступен: fail-open, работу доски не блокируем.
        }

        // Исполнитель назначается автором при создании: обязателен и строго из отдела проекта.
        [$assignee, $assigneeError] = $this->resolveAssignee($data['assignee_id'] ?? $data['assignee'] ?? null, $project);
        if ($assigneeError !== null) {
            return $this->fail($assigneeError);
        }
        $assigneeId = (int) $assignee->get('id');

        $limit = (int) $this->modx->getOption('mxboard.wip_limit', null, 0);
        if ($limit > 0 && $this->openCountFor($assigneeId) >= $limit) {
            return $this->fail('mxboard_err_wip_limit');
        }

        $column = $this->columnBy($project, ['is_initial' => true]);
        if (!$column) {
            return $this->fail('mxboard_err_no_initial_column');
        }

        $now = time();
        $num = $this->makeNum($now);

        /** @var MxBoardTask $task */
        $task = $this->modx->newObject(MxBoardTask::class);
        $task->fromArray([
            'project_id' => (int) $project->get('id'),
            'parent_id' => $parentId,
            'type_id' => (int) $type->get('id'),
            'column_id' => (int) $column->get('id'),
            'num' => $num,
            'title' => $title,
            'tor' => (string) ($data['tor'] ?? ''),
            'author_id' => (int) $user->get('id'),
            'assignee_id' => $assigneeId,
            'priority' => (int) ($data['priority'] ?? 0),
            'position' => $this->nextPosition((int) $column->get('id')),
            'deadlineon' => $deadline,
            'deadline_disputed' => 0,
            'deadline_proposed' => 0,
            'plan_hours' => $this->normalizePlan($data['plan_hours'] ?? $data['plan'] ?? null),
            'plan_disputed' => 0,
            'plan_proposed' => 0,
            'fields' => $fields,
            'meta' => $this->decodeJson($data['meta'] ?? null),
            'ai_verdict' => $aiVerdict,
            'createdon' => $now,
            'updatedon' => $now,
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        if ($aiVerdict !== null) {
            $this->logAiCheck((int) $task->get('id'), $user, $aiVerdict, $title, $channel);
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
     * Перевести карточку в колонку. Здесь же — единственная точка закрытия задачи.
     *
     * Инвариант: в финальную колонку нельзя, пока есть незавершённая подзадача. Это
     * структурная блокировка — она действует и на автора/менеджера, а не только на роли.
     *
     * Здесь же ведётся замер фактического времени: вход в стартовую стадию (или правее)
     * запускает отсчёт, возврат левее — обнуляет его (см. trackTime).
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
        $task->set('startedon', $this->trackTime($project, $task, $target, $now));

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
     * Комментарий к карточке — как участники отчитываются о ходе работы.
     *
     * Комментировать вправе тот, кто видит задачу (автор/исполнитель/соисполнитель
     * подзадачи/менеджер) — та же логика, что и детальный просмотр.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function comment(modUser $user, int $taskId, string $content, string $channel = 'mcp', bool $allowEmpty = false): array
    {
        // Пустой текст допустим только для сообщения-вложения (allowEmpty=1 от чат-UI,
        // где к комменту тут же цепляются файлы). Для REST/MCP текст по-прежнему обязателен.
        $content = trim($content);
        if ($content === '' && !$allowEmpty) {
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
     * Редактировать комментарий. Только автор.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function updateComment(modUser $user, int $commentId, string $content, string $channel = 'mgr'): array
    {
        $content = trim($content);
        if ($content === '') {
            return $this->fail('mxboard_err_comment_empty');
        }

        /** @var MxBoardComment|null $comment */
        $comment = $this->modx->getObject(MxBoardComment::class, $commentId);
        if (!$comment) {
            return $this->fail('mxboard_err_comment_not_found');
        }

        if ((int) $user->get('id') !== (int) $comment->get('user_id')) {
            return $this->fail('mxboard_err_comment_author_only');
        }

        $comment->set('content', $content);
        $comment->set('updatedon', time());

        if (!$comment->save()) {
            return $this->fail('mxboard_err_save');
        }

        return [
            'success' => true,
            'message' => '',
            'object' => $comment->toArray(),
        ];
    }

    /**
     * Удалить комментарий. Только автор.
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function deleteComment(modUser $user, int $commentId, string $channel = 'mgr'): array
    {
        /** @var MxBoardComment|null $comment */
        $comment = $this->modx->getObject(MxBoardComment::class, $commentId);
        if (!$comment) {
            return $this->fail('mxboard_err_comment_not_found');
        }

        if ((int) $user->get('id') !== (int) $comment->get('user_id')) {
            return $this->fail('mxboard_err_comment_author_only');
        }

        // Вложения сообщения: физфайлы удаляем явно (composite снял бы лишь записи и только
        // по задаче) ДО удаления коммента.
        (new AttachmentService($this->modx))->purgeForComment($commentId);

        if (!$comment->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return ['success' => true, 'message' => '', 'object' => null];
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
     * Оспорить плановое время: исполнитель предлагает свою оценку в часах с причиной.
     * Меняет план не он — только автор через resolvePlan.
     *
     * Оспаривать нечего, пока план не задан: поле необязательное, «оценки не было» —
     * это не повод для спора, автор просто ещё не оценивал.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function disputePlan(modUser $user, int $taskId, int $proposedHours, string $reason = '', string $channel = 'mcp'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        if ((int) $user->get('id') !== (int) $task->get('assignee_id')) {
            return $this->fail('mxboard_err_dispute_assignee_only');
        }

        if ((int) $task->get('plan_hours') <= 0) {
            return $this->fail('mxboard_err_plan_not_set');
        }

        if ($proposedHours <= 0) {
            return $this->fail('mxboard_err_plan_required');
        }

        $task->fromArray([
            'plan_disputed' => 1,
            'plan_proposed' => $proposedHours,
            'updatedon' => time(),
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, 'plan_dispute', '', '', mb_substr($reason, 0, 255), $channel);
        $this->fireEvent('mxbOnPlanDispute', $task, $user, ['channel' => $channel, 'proposed' => $proposedHours, 'reason' => $reason]);

        return $this->ok($task);
    }

    /**
     * Разрешить оспаривание плана: автор/менеджер принимает (план = предложенный)
     * или отклоняет (остаётся прежний). В обоих случаях флаг и предложение сбрасываются.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function resolvePlan(modUser $user, int $taskId, bool $accept, string $channel = 'mgr'): array
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

        if (!(bool) $task->get('plan_disputed')) {
            return $this->fail('mxboard_err_no_dispute');
        }

        $proposed = (int) $task->get('plan_proposed');
        if ($accept && $proposed > 0) {
            $task->set('plan_hours', $proposed);
        }
        $task->fromArray([
            'plan_disputed' => 0,
            'plan_proposed' => 0,
            'updatedon' => time(),
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, $accept ? 'plan_accepted' : 'plan_rejected', '', '', '', $channel);
        $this->fireEvent('mxbOnPlanResolve', $task, $user, ['channel' => $channel, 'accepted' => $accept]);

        return $this->ok($task);
    }

    /**
     * Правка карточки автором/менеджером: заголовок, ToR, приоритет, дедлайн,
     * плановое время, тип, поля.
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

        // Переназначение: новый исполнитель тоже строго из отдела проекта.
        if (array_key_exists('assignee_id', $data) || array_key_exists('assignee', $data)) {
            [$assignee, $assigneeError] = $this->resolveAssignee($data['assignee_id'] ?? $data['assignee'], $project);
            if ($assigneeError !== null) {
                return $this->fail($assigneeError);
            }
            $task->set('assignee_id', (int) $assignee->get('id'));
        }

        if (array_key_exists('deadline', $data) || array_key_exists('deadlineon', $data)) {
            $deadline = $this->normalizeDeadline($data['deadline'] ?? $data['deadlineon']);
            if ($deadline <= 0) {
                return $this->fail('mxboard_err_deadline_required');
            }
            $task->set('deadlineon', $deadline);
        }

        // Плановое время: 0 допустимо (снять оценку). Явная правка автором закрывает спор —
        // иначе флаг «оспорено» висел бы уже над другой, свежей оценкой.
        if (array_key_exists('plan_hours', $data) || array_key_exists('plan', $data)) {
            $task->fromArray([
                'plan_hours' => $this->normalizePlan($data['plan_hours'] ?? $data['plan']),
                'plan_disputed' => 0,
                'plan_proposed' => 0,
            ]);
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

        // Физфайлы всех вложений (задачи и её комментов) — до remove(): composite снимет
        // только записи, файлы в источнике останутся сиротами, если их не снести явно.
        (new AttachmentService($this->modx))->purgeForTask($taskId);

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
        // Fallback: у проекта без своих колонок берём глобальный шаблон (project_id = 0).
        $scope = Columns::scope($this->modx, (int) $project->get('id'));
        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(
            MxBoardColumn::class,
            array_merge(['project_id' => $scope], $criteria)
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
     * Разрешить и провалидировать исполнителя. Обязателен и СТРОГО из отдела проекта
     * (автор может быть из другого отдела — «программист ставит задачу дизайнеру», —
     * но работать будет член отдела проекта).
     *
     * @param mixed $ref id (int) или username (string)
     *
     * @return array{0: modUser|null, 1: string|null} [исполнитель, ключ-ошибки]
     */
    private function resolveAssignee(mixed $ref, MxBoardProject $project): array
    {
        if ($ref === null || $ref === '' || $ref === 0 || $ref === '0') {
            return [null, 'mxboard_err_assignee_required'];
        }

        $criteria = is_numeric($ref) ? ['id' => (int) $ref] : ['username' => (string) $ref];
        /** @var modUser|null $assignee */
        $assignee = $this->modx->getObject(modUser::class, $criteria);
        if (!$assignee || !(bool) $assignee->get('active')) {
            return [null, 'mxboard_err_assignee_not_found'];
        }

        /** @var MxBoardDepartment|null $department */
        $department = $this->modx->getObject(MxBoardDepartment::class, (int) $project->get('department_id'));
        $usergroupId = $department ? (int) $department->get('usergroup_id') : 0;
        if ($usergroupId <= 0) {
            return [null, 'mxboard_err_department_not_found'];
        }

        if (!Transitions::isDepartmentMember($this->modx, (int) $assignee->get('id'), $usergroupId)) {
            return [null, 'mxboard_err_assignee_not_in_department'];
        }

        return [$assignee, null];
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
            // Тип `files` — файловая зона задачи: файлы живут вложениями (comment_id=0),
            // а не в task.fields. В fields ничего не пишем и required по нему не проверяем
            // (файлы грузятся после создания задачи, когда уже есть task_id).
            if ((string) $field->get('type') === 'files') {
                continue;
            }

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

    /**
     * Определения полей типа (key/label/type/required) для промпта ИИ-проверки.
     *
     * @return list<array{key: string, label: string, type: string, required: bool}>
     */
    private function fieldDefs(MxBoardTaskType $type): array
    {
        $c = $this->modx->newQuery(MxBoardField::class);
        $c->where(['task_type_id' => (int) $type->get('id')]);
        $c->sortby('position', 'ASC');

        $out = [];
        /** @var MxBoardField $field */
        foreach ($this->modx->getCollection(MxBoardField::class, $c) as $field) {
            $out[] = [
                'key' => (string) $field->get('key'),
                'label' => (string) $field->get('label'),
                'type' => (string) $field->get('type'),
                'required' => (bool) $field->get('required'),
            ];
        }

        return $out;
    }

    /**
     * Отказ ИИ-проверки: вердикт едет фронту в object, чтобы показать чего не хватает.
     * В soft-режиме фронт даст кнопку «всё равно создать» (повтор с ai_override=1).
     *
     * @param array{complete: bool, score: int, missing: list<string>, summary: string} $verdict
     *
     * @return array{success: bool, message: string, object: array<string, mixed>}
     */
    private function aiReject(array $verdict, string $mode): array
    {
        $message = $verdict['summary'] !== ''
            ? $verdict['summary']
            : ($this->modx->lexicon('mxboard_err_ai_incomplete') ?: 'mxboard_err_ai_incomplete');

        return [
            'success' => false,
            'message' => $message,
            'object' => [
                'ai_incomplete' => true,
                'mode' => $mode,
                'can_override' => $mode === 'soft',
                'verdict' => $verdict,
            ],
        ];
    }

    /**
     * Запись вердикта ИИ-проверки в журнал доски. При strict-отказе задачи ещё нет —
     * тогда taskId = 0 (аудит), в note кладём заголовок отклонённой задачи.
     *
     * @param array{complete: bool, score: int, missing: list<string>, summary: string} $verdict
     */
    private function logAiCheck(int $taskId, modUser $user, array $verdict, string $title, string $channel): void
    {
        $note = sprintf(
            '%s score=%d %s',
            $verdict['complete'] ? 'ok' : 'incomplete',
            $verdict['score'],
            $verdict['summary'] !== '' ? $verdict['summary'] : implode('; ', $verdict['missing'])
        );
        if ($taskId === 0) {
            $note = '[' . $title . '] ' . $note;
        }

        /** @var MxBoardLog $log */
        $log = $this->modx->newObject(MxBoardLog::class);
        $log->fromArray([
            'task_id' => $taskId,
            'user_id' => (int) $user->get('id'),
            'action' => 'ai_check',
            'from_column' => '',
            'to_column' => '',
            'note' => mb_substr($note, 0, 255),
            'channel' => $channel,
            'createdon' => time(),
        ]);
        $log->save();
    }

    /**
     * Новое значение startedon после перевода карточки в $target — замер фактического времени.
     *
     * Точка отсчёта — стадия с is_start: вход в неё ИЛИ в любую правее (по position) запускает
     * замер, если он ещё не идёт. Возврат левее (в коробке это бэклог) обнуляет замер, а не
     * ставит на паузу: работа началась заново, прежний отсчёт не имеет смысла.
     *
     * Отсчёта нет вовсе, если стартовая стадия у проекта не помечена, — и мы не выдумываем
     * старт задаче, которую закрыли, минуя работу (startedon = 0 → факт остаётся неизвестен).
     */
    private function trackTime(MxBoardProject $project, MxBoardTask $task, MxBoardColumn $target, int $now): int
    {
        $started = (int) $task->get('startedon');

        $start = $this->columnBy($project, ['is_start' => true]);
        if (!$start) {
            return $started;
        }

        if ((int) $target->get('position') < (int) $start->get('position')) {
            return 0;
        }

        if ($started > 0) {
            return $started;
        }

        return (bool) $target->get('is_final') ? 0 : $now;
    }

    /** Есть ли у задачи незавершённые подзадачи (closedon = 0 = не в финальной стадии). */
    private function hasOpenSubtasks(int $taskId): bool
    {
        return (int) $this->modx->getCount(MxBoardTask::class, [
            'parent_id' => $taskId,
            'closedon' => 0,
        ]) > 0;
    }

    /** Сколько незакрытых карточек на исполнителе (для wip_limit), по всем проектам. */
    private function openCountFor(int $userId): int
    {
        return (int) $this->modx->getCount(MxBoardTask::class, [
            'assignee_id' => $userId,
            'closedon' => 0,
        ]);
    }

    /**
     * Найти задачу по адресу: числовой id ИЛИ человекочитаемый num (напр. 2607-15).
     * Позволяет MCP/REST принимать оба — num устойчив к переносу базы задач.
     */
    public function resolveTaskRef(mixed $ref): ?MxBoardTask
    {
        if (is_int($ref) || (is_string($ref) && ctype_digit(trim($ref)))) {
            $id = (int) $ref;

            return $id > 0 ? $this->modx->getObject(MxBoardTask::class, $id) : null;
        }
        $num = trim((string) $ref);
        if ($num === '') {
            return null;
        }

        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, ['num' => $num]);

        return $task;
    }

    /**
     * Сгенерировать уникальный номер задачи по шаблону mxboard.task_num_format.
     * Счётчик берётся атомарно под блокировкой строки (nextCounter).
     */
    private function makeNum(int $when): string
    {
        $format = trim((string) $this->modx->getOption('mxboard.task_num_format', null, TaskNum::DEFAULT_FORMAT));
        if ($format === '') {
            $format = TaskNum::DEFAULT_FORMAT;
        }
        $seq = $this->nextCounter(TaskNum::period($format, $when));

        return TaskNum::render($format, $when, $seq);
    }

    /**
     * Следующий порядковый номер периода. INSERT IGNORE гарантирует строку, UPDATE под
     * блокировкой строки сериализует конкурентные вставки, SELECT в той же транзакции
     * читает уже увеличенное значение — устойчиво к гонкам и к удалению задач.
     */
    private function nextCounter(string $period): int
    {
        $table = $this->modx->getTableName(MxBoardCounter::class);

        $this->modx->beginTransaction();
        try {
            $this->modx->prepare("INSERT IGNORE INTO {$table} (period, value) VALUES (?, 0)")->execute([$period]);
            $this->modx->prepare("UPDATE {$table} SET value = value + 1 WHERE period = ?")->execute([$period]);
            $sel = $this->modx->prepare("SELECT value FROM {$table} WHERE period = ?");
            $sel->execute([$period]);
            $value = (int) $sel->fetchColumn();
            $this->modx->commit();

            return $value;
        } catch (\Throwable $e) {
            $this->modx->rollback();
            throw $e;
        }
    }

    private function nextPosition(int $columnId): int
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->where(['column_id' => $columnId]);
        $c->select('MAX(position)');
        $max = (int) $this->modx->getValue($c);

        return $max + 1;
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
     * Разобрать плановое время из любого канала. Единица — целые часы: дробный ввод
     * («2.5», «1,5») округляем по математическим правилам, отрицательное и мусор → 0
     * («не оценивали»). Централизовано здесь по той же причине, что и дедлайн: фасады
     * приводить типы не должны, иначе каналы разъедутся.
     */
    private function normalizePlan(mixed $value): int
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }
        if (!is_numeric($value)) {
            return 0;
        }

        $hours = (int) round((float) $value);

        return $hours > 0 ? $hours : 0;
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
        if (!$log->save()) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard] Не удалось записать лог: task#' . (int) $task->get('id') . ' action=' . $action);
        }
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

        // In-app уведомления (SSE) — своя, встроенная реакция доски на то же событие.
        // Обёрнуто отдельно: сбой уведомлений не должен мешать внешним плагинам и наоборот.
        try {
            (new NotificationService($this->modx))->emit($event, $task, $user, $extra);
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_ERROR, "[mxBoard] Уведомление {$event}: " . $e->getMessage());
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
