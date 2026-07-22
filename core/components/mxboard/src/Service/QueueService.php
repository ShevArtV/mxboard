<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Helpers\Columns;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardProject;
use MxBoard\Model\MxBoardQueue;
use MxBoard\Model\MxBoardTask;

/**
 * Очереди задач проекта: упорядоченный список карточек, который стартует вручную и
 * дальше едет сам.
 *
 * Смысл фичи: задачи в бэклоге лежат кучей, а работать над ними надо в определённом
 * порядке. Очередь фиксирует этот порядок, а закрытие задачи очереди автоматически
 * вытягивает в работу следующую — без участия человека и без внешнего оркестратора.
 *
 * Устройство: сама очередь — строка `mxboard_queue`, а членство задачи хранится полями
 * `mxboard_task.queue_id` / `queue_position`. Отдельной таблицы связки нет намеренно:
 * задача состоит максимум в одной очереди (событие несёт единственный ключ `queue`),
 * и при полях в задаче этот инвариант держать не надо, а payload событий и лента
 * /events получают id очереди без лишнего JOIN.
 *
 * Стадии нигде не захардкожены: «начальная» — колонка с `is_initial`, «стартовая» —
 * с `is_start`, «финальная» — с `is_final`.
 *
 * Право на управление очередями = «менеджер отдела проекта», то есть тот же, кто
 * управляет самим проектом (Transitions::isDepartmentManager).
 */
class QueueService
{
    /**
     * Канал журнала для автоматического перевода задачи очередью.
     *
     * Нужен, чтобы в логе и в ленте /events автозапуск отличался от ручного перевода:
     * снаружи оба выглядят как обычный `move` от автора задачи.
     */
    public const CHANNEL = 'queue';

    /**
     * Guard: внутри автозапуска второй автозапуск не стартуем.
     *
     * Кандидат берётся только из начальной стадии, а стартовая финальной не бывает, так что
     * цепочки быть не должно — но флаг дешевле, чем разбираться с рекурсией на живой доске,
     * если у кого-то стартовая и финальная стадии совпадут.
     */
    private static bool $advancing = false;

    public function __construct(private modX $modx)
    {
    }

    /**
     * Очереди проекта. С `$withTasks` — вместе с задачами очереди (номер, заголовок, порядок).
     *
     * Читать очереди вправе любой, кто видит доску: это тот же уровень, что список колонок.
     * Закрытые задачи в выдачу не попадают — очередь показывает предстоящую работу, а
     * членство закрытой задачи сохраняется только ради ключа `queue` в её событиях.
     *
     * @return list<array<string, mixed>>
     */
    public function queues(int $projectId, bool $withTasks = false): array
    {
        $c = $this->modx->newQuery(MxBoardQueue::class);
        $c->where(['project_id' => $projectId]);
        $c->sortby('position', 'ASC');
        $c->sortby('id', 'ASC');

        $out = [];
        /** @var MxBoardQueue $queue */
        foreach ($this->modx->getCollection(MxBoardQueue::class, $c) as $queue) {
            $row = $this->queueRow($queue);
            if ($withTasks) {
                $row['tasks'] = $this->tasksOf((int) $queue->get('id'));
                $row['tasks_count'] = count($row['tasks']);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Создать очередь в проекте. Право: менеджер отдела проекта.
     *
     * `key` можно не передавать — сгенерируем из названия: очередями управляют и агенты
     * по MCP, а им удобнее адресовать очередь ключом, как проект или стадию.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function create(modUser $user, array $data): array
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        /** @var MxBoardProject|null $project */
        $project = $projectId > 0 ? $this->modx->getObject(MxBoardProject::class, $projectId) : null;
        if (!$project) {
            return $this->fail('mxboard_err_project_not_found');
        }
        if (!Transitions::isDepartmentManager($this->modx, $user, (int) $project->get('department_id'))) {
            return $this->fail('mxboard_err_queue_denied');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->fail('mxboard_err_queue_name_required');
        }

        $key = trim((string) ($data['key'] ?? ''));
        $key = $key !== '' ? $key : $this->makeKey($name, $projectId);
        if ($this->modx->getObject(MxBoardQueue::class, ['project_id' => $projectId, 'key' => $key])) {
            return $this->fail('mxboard_err_queue_key_exists');
        }

        $now = time();

        /** @var MxBoardQueue $queue */
        $queue = $this->modx->newObject(MxBoardQueue::class);
        $queue->fromArray([
            'project_id' => $projectId,
            'key' => $key,
            'name' => $name,
            'description' => (string) ($data['description'] ?? ''),
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'position' => (int) ($data['position'] ?? 0),
            'createdon' => $now,
            'updatedon' => $now,
        ]);
        if (!$queue->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($this->queueRow($queue));
    }

    /**
     * Правка очереди: name, description, key, active, position. Проект не меняется —
     * это была бы не правка, а перенос очереди вместе с чужими задачами.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function update(modUser $user, int $id, array $data): array
    {
        /** @var MxBoardQueue|null $queue */
        $queue = $this->modx->getObject(MxBoardQueue::class, $id);
        if (!$queue) {
            return $this->fail('mxboard_err_queue_not_found');
        }
        $error = $this->gate($user, (int) $queue->get('project_id'));
        if ($error !== null) {
            return $this->fail($error);
        }

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->fail('mxboard_err_queue_name_required');
            }
            $queue->set('name', $name);
        }
        if (array_key_exists('key', $data)) {
            $key = trim((string) $data['key']);
            if ($key === '') {
                return $this->fail('mxboard_err_queue_key_exists');
            }
            $taken = $this->modx->getObject(MxBoardQueue::class, [
                'project_id' => (int) $queue->get('project_id'),
                'key' => $key,
                'id:!=' => $id,
            ]);
            if ($taken) {
                return $this->fail('mxboard_err_queue_key_exists');
            }
            $queue->set('key', $key);
        }
        if (array_key_exists('description', $data)) {
            $queue->set('description', (string) $data['description']);
        }
        if (array_key_exists('active', $data)) {
            $queue->set('active', (bool) $data['active']);
        }
        if (array_key_exists('position', $data)) {
            $queue->set('position', (int) $data['position']);
        }

        $queue->set('updatedon', time());
        if (!$queue->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok($this->queueRow($queue));
    }

    /**
     * Удалить очередь. Задачи остаются — у них лишь обнуляется членство.
     *
     * Именно поэтому связь очередь→задачи в схеме не composite: xPDO снёс бы вместе с
     * очередью сами карточки.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function remove(modUser $user, int $id): array
    {
        /** @var MxBoardQueue|null $queue */
        $queue = $this->modx->getObject(MxBoardQueue::class, $id);
        if (!$queue) {
            return $this->fail('mxboard_err_queue_not_found');
        }
        $error = $this->gate($user, (int) $queue->get('project_id'));
        if ($error !== null) {
            return $this->fail($error);
        }

        $this->modx->updateCollection(MxBoardTask::class, [
            'queue_id' => 0,
            'queue_position' => 0,
        ], ['queue_id' => $id]);

        if (!$queue->remove()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok(['id' => $id]);
    }

    /**
     * Поставить задачу в очередь.
     *
     * `$queueId = 0` — «в единственную очередь проекта»: если очередь у проекта одна,
     * выбирать не из чего (UI в этом случае не показывает диалог). Если очередей
     * несколько, а очередь не названа — просим выбрать.
     *
     * Ставить можно только из НАЧАЛЬНОЙ стадии: очередь управляет запуском работы, а
     * задача, которая уже в работе, запуска не ждёт.
     *
     * Право: автор задачи или менеджер отдела проекта.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function addTask(modUser $user, int $taskId, int $queueId = 0): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $projectId = (int) $task->get('project_id');
        if (!$this->canAssign($user, $task)) {
            return $this->fail('mxboard_err_queue_denied');
        }

        $column = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
        if (!$column || !(bool) $column->get('is_initial')) {
            return $this->fail('mxboard_err_queue_not_initial');
        }

        if ($queueId > 0) {
            /** @var MxBoardQueue|null $queue */
            $queue = $this->modx->getObject(MxBoardQueue::class, $queueId);
            if (!$queue) {
                return $this->fail('mxboard_err_queue_not_found');
            }
            if ((int) $queue->get('project_id') !== $projectId) {
                return $this->fail('mxboard_err_queue_foreign_project');
            }
        } else {
            $queues = $this->modx->getCollection(MxBoardQueue::class, ['project_id' => $projectId, 'active' => true]);
            if (count($queues) !== 1) {
                return $this->fail($queues ? 'mxboard_err_queue_required' : 'mxboard_err_queue_none');
            }
            /** @var MxBoardQueue $queue */
            $queue = reset($queues);
            $queueId = (int) $queue->get('id');
        }

        $task->set('queue_id', $queueId);
        $task->set('queue_position', $this->nextPosition($queueId));
        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok([
            'task_id' => $taskId,
            'queue' => $this->queueRow($queue),
            'queue_position' => (int) $task->get('queue_position'),
        ]);
    }

    /**
     * Вынуть задачу из очереди.
     *
     * Без этого ошибочно поставленную карточку не убрать, и очередь начнёт запускать не то.
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function removeTask(modUser $user, int $taskId): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }
        if ((int) $task->get('queue_id') <= 0) {
            return $this->fail('mxboard_err_queue_task_not_in');
        }
        if (!$this->canAssign($user, $task)) {
            return $this->fail('mxboard_err_queue_denied');
        }

        $task->set('queue_id', 0);
        $task->set('queue_position', 0);
        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        return $this->ok(['task_id' => $taskId]);
    }

    /**
     * Переупорядочить очередь: `$orderedTaskIds` — полный список задач очереди в новом порядке.
     *
     * Полная перестановка, а не «сдвинуть одну» — так порядок нельзя оставить дырявым
     * (тот же приём, что в StructureService::reorderColumns).
     *
     * @param list<int> $orderedTaskIds
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function reorder(modUser $user, int $queueId, array $orderedTaskIds): array
    {
        /** @var MxBoardQueue|null $queue */
        $queue = $this->modx->getObject(MxBoardQueue::class, $queueId);
        if (!$queue) {
            return $this->fail('mxboard_err_queue_not_found');
        }
        $error = $this->gate($user, (int) $queue->get('project_id'));
        if ($error !== null) {
            return $this->fail($error);
        }

        $ids = array_values(array_unique(array_map('intval', $orderedTaskIds)));
        if ($ids === []) {
            return $this->fail('mxboard_err_reorder_mismatch');
        }

        /** @var array<int, MxBoardTask> $members */
        $members = [];
        foreach ($this->openTasks($queueId) as $task) {
            $members[(int) $task->get('id')] = $task;
        }
        if (count($ids) !== count($members)) {
            return $this->fail('mxboard_err_reorder_mismatch');
        }
        foreach ($ids as $id) {
            if (!isset($members[$id])) {
                return $this->fail('mxboard_err_reorder_mismatch');
            }
        }

        $this->modx->beginTransaction();
        foreach ($ids as $pos => $id) {
            $members[$id]->set('queue_position', $pos);
            if (!$members[$id]->save()) {
                $this->modx->rollback();
                return $this->fail('mxboard_err_save');
            }
        }
        $this->modx->commit();

        return $this->ok(['queue_id' => $queueId, 'order' => $ids]);
    }

    /**
     * Сделать задачу первой в её очереди, остальные сдвинуть ниже.
     *
     * Вызывается, когда пользователь стартует очередь не с первой задачи и подтвердил,
     * что порядок изменится. Право здесь не «управление очередью», а право двигать саму
     * карточку: перестановка — следствие разрешённого перевода, и проверит его move().
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function promote(modUser $user, int $taskId): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $queueId = (int) $task->get('queue_id');
        if ($queueId <= 0) {
            return $this->fail('mxboard_err_queue_task_not_in');
        }

        // Право ровно то, ради которого перестановка и затевается: перевести эту карточку
        // в стартовую стадию. Иначе promote был бы дырой — любой залогиненный менял бы
        // чужую очередь, не имея права двигать в ней ни одной задачи.
        $start = $this->columnByFlag((int) $task->get('project_id'), 'is_start');
        if (!$start) {
            return $this->fail('mxboard_err_queue_no_start_column');
        }
        $verdict = Transitions::can($this->modx, $user, $task, $start);
        if (!$verdict['allowed']) {
            return $this->fail($verdict['reason']);
        }

        $ids = [$taskId];
        foreach ($this->openTasks($queueId) as $member) {
            $id = (int) $member->get('id');
            if ($id !== $taskId) {
                $ids[] = $id;
            }
        }

        return $this->reorderAsSystem($queueId, $ids);
    }

    /**
     * Автозапуск: задача очереди доехала до финальной стадии — тянем следующую в стартовую.
     *
     * Кандидат — первая по порядку незакрытая задача очереди, которая ещё стоит в НАЧАЛЬНОЙ
     * стадии. Задачу, которую уже двигали руками, автозапуск не трогает: тащить её назад в
     * стартовую было бы хуже, чем не сделать ничего.
     *
     * Перевод идёт обычным TaskService::move() от имени АВТОРА следующей задачи — значит
     * работают штатные права, журнал и события (mxbOnTaskMove и остальные). Обхода правил
     * здесь нет: если автор не вправе двигать свою карточку, очередь просто не поедет.
     *
     * @return array<string, mixed>|null данные перевода или null, если двигать некого/нечем
     */
    public function advance(MxBoardTask $closed): ?array
    {
        $queueId = (int) $closed->get('queue_id');
        if ($queueId <= 0 || self::$advancing) {
            return null;
        }

        $projectId = (int) $closed->get('project_id');
        $start = $this->columnByFlag($projectId, 'is_start');
        if (!$start) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                "[mxBoard] Очередь #{$queueId}: у проекта #{$projectId} нет стартовой стадии — автозапуск пропущен."
            );

            return null;
        }

        $initialId = $this->columnByFlag($projectId, 'is_initial')?->get('id');
        if (!$initialId) {
            return null;
        }

        $next = null;
        foreach ($this->openTasks($queueId) as $candidate) {
            if ((int) $candidate->get('id') === (int) $closed->get('id')) {
                continue;
            }
            if ((int) $candidate->get('column_id') === (int) $initialId) {
                $next = $candidate;
                break;
            }
        }
        if (!$next) {
            return null;
        }

        /** @var modUser|null $author */
        $author = $this->modx->getObject(modUser::class, (int) $next->get('author_id'));
        if (!$author) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[mxBoard] Очередь #' . $queueId . ': у задачи #' . (int) $next->get('id') . ' нет автора — автозапуск пропущен.'
            );

            return null;
        }

        self::$advancing = true;
        try {
            $result = (new TaskService($this->modx))->move(
                $author,
                (int) $next->get('id'),
                (string) $start->get('key'),
                $this->modx->lexicon('mxboard_queue_auto_note') ?: 'Автозапуск очереди',
                self::CHANNEL
            );
        } finally {
            self::$advancing = false;
        }

        if (!$result['success']) {
            $this->modx->log(
                modX::LOG_LEVEL_WARN,
                '[mxBoard] Очередь #' . $queueId . ': задачу #' . (int) $next->get('id')
                . ' не удалось запустить автоматически — ' . $result['message']
            );

            return null;
        }

        return [
            'queue_id' => $queueId,
            'task_id' => (int) $next->get('id'),
            'column' => (string) $start->get('key'),
            'actor_id' => (int) $author->get('id'),
        ];
    }

    /** Первая ли задача в своей очереди (среди незакрытых). Для предупреждения при старте. */
    public function isFirst(MxBoardTask $task): bool
    {
        $queueId = (int) $task->get('queue_id');
        if ($queueId <= 0) {
            return true;
        }

        $first = $this->openTasks($queueId)[0] ?? null;

        return $first === null || (int) $first->get('id') === (int) $task->get('id');
    }

    /**
     * Перестановка без проверки прав управления очередью — для promote(), где право уже
     * подтверждено правом на перевод карточки.
     *
     * @param list<int> $ids
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    private function reorderAsSystem(int $queueId, array $ids): array
    {
        /** @var array<int, MxBoardTask> $members */
        $members = [];
        foreach ($this->openTasks($queueId) as $task) {
            $members[(int) $task->get('id')] = $task;
        }

        $this->modx->beginTransaction();
        foreach ($ids as $pos => $id) {
            if (!isset($members[$id])) {
                continue;
            }
            $members[$id]->set('queue_position', $pos);
            if (!$members[$id]->save()) {
                $this->modx->rollback();
                return $this->fail('mxboard_err_save');
            }
        }
        $this->modx->commit();

        return $this->ok(['queue_id' => $queueId, 'order' => $ids]);
    }

    /**
     * Незакрытые задачи очереди по порядку.
     *
     * @return list<MxBoardTask>
     */
    private function openTasks(int $queueId): array
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->where(['queue_id' => $queueId, 'closedon' => 0]);
        $c->sortby('queue_position', 'ASC');
        $c->sortby('id', 'ASC');

        return array_values($this->modx->getCollection(MxBoardTask::class, $c));
    }

    /**
     * Задачи очереди для UI: номер, заголовок, стадия.
     *
     * @return list<array<string, mixed>>
     */
    private function tasksOf(int $queueId): array
    {
        $out = [];
        foreach ($this->openTasks($queueId) as $pos => $task) {
            $column = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
            $out[] = [
                'id' => (int) $task->get('id'),
                'num' => (string) $task->get('num'),
                'title' => (string) $task->get('title'),
                'queue_position' => $pos,
                'column_key' => $column ? (string) $column->get('key') : '',
                'column_name' => $column ? (string) $column->get('name') : '',
                'is_initial' => $column ? (bool) $column->get('is_initial') : false,
            ];
        }

        return $out;
    }

    /** Колонка проекта по флагу (`is_initial` / `is_start` / `is_final`) с учётом fallback-шаблона. */
    private function columnByFlag(int $projectId, string $flag): ?MxBoardColumn
    {
        $scope = Columns::scope($this->modx, $projectId);

        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(MxBoardColumn::class, ['project_id' => $scope, $flag => true]);

        return $column;
    }

    /** Право управлять очередями проекта: тот же, кто управляет проектом. */
    private function gate(modUser $user, int $projectId): ?string
    {
        /** @var MxBoardProject|null $project */
        $project = $this->modx->getObject(MxBoardProject::class, $projectId);
        if (!$project) {
            return 'mxboard_err_project_not_found';
        }

        return Transitions::isDepartmentManager($this->modx, $user, (int) $project->get('department_id'))
            ? null
            : 'mxboard_err_queue_denied';
    }

    /**
     * Кто вправе ставить/снимать карточку с очереди: автор задачи или менеджер отдела.
     *
     * Шире, чем управление самой очередью: состав очереди — часть работы над карточкой,
     * и заставлять автора звать менеджера ради постановки своей задачи бессмысленно.
     */
    private function canAssign(modUser $user, MxBoardTask $task): bool
    {
        $userId = (int) $user->get('id');
        if ($userId > 0 && $userId === (int) $task->get('author_id')) {
            return true;
        }

        return Transitions::isManager($this->modx, $user, $task);
    }

    /** Хвост очереди: позиция для новой задачи. */
    private function nextPosition(int $queueId): int
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->where(['queue_id' => $queueId]);
        $c->select('MAX(queue_position)');
        $c->prepare();
        if ($c->stmt && $c->stmt->execute()) {
            return ((int) $c->stmt->fetchColumn()) + 1;
        }

        return 0;
    }

    /**
     * Ключ очереди из названия: латиница/цифры/дефис, кириллица транслитом.
     *
     * Пусто после нормализации (например, название из одних иероглифов) — берём `queue`
     * и добавляем числовой суффикс до уникальности в проекте.
     */
    private function makeKey(string $name, int $projectId): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        $base = strtr(mb_strtolower(trim($name)), $map);
        $base = (string) preg_replace('~[^a-z0-9]+~', '-', $base);
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'queue';
        }
        $base = mb_substr($base, 0, 90);

        $key = $base;
        $suffix = 1;
        while ($this->modx->getObject(MxBoardQueue::class, ['project_id' => $projectId, 'key' => $key])) {
            $key = $base . '-' . (++$suffix);
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function queueRow(MxBoardQueue $queue): array
    {
        $projectId = (int) $queue->get('project_id');
        /** @var MxBoardProject|null $project */
        $project = $this->modx->getObject(MxBoardProject::class, $projectId);

        return [
            'id' => (int) $queue->get('id'),
            'project_id' => $projectId,
            'project_key' => $project ? (string) $project->get('key') : '',
            'project_name' => $project ? (string) $project->get('name') : '',
            'key' => (string) $queue->get('key'),
            'name' => (string) $queue->get('name'),
            'description' => (string) $queue->get('description'),
            'active' => (bool) $queue->get('active'),
            'position' => (int) $queue->get('position'),
        ];
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array{success: bool, message: string, object: array<string, mixed>}
     */
    private function ok(array $object): array
    {
        return ['success' => true, 'message' => '', 'object' => $object];
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
