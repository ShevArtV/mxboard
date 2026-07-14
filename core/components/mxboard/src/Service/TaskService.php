<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardBoard;
use MxBoard\Model\MxBoardColumn;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardLog;
use MxBoard\Model\MxBoardTask;

/**
 * Вся логика жизненного цикла карточки в одном месте.
 *
 * Процессоры (менеджер), REST и MCP — тонкие обёртки над этим сервисом: правила
 * перехода и журнал не должны зависеть от того, каким каналом пришёл запрос,
 * иначе агент найдёт канал, где проверка слабее.
 */
class TaskService
{
    public function __construct(private modX $modx)
    {
    }

    /**
     * Создать карточку. Попадает в initial-колонку доски.
     *
     * @param array<string, mixed> $data
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function create(modUser $user, array $data, string $channel = 'mgr'): array
    {
        $board = $this->resolveBoard($data['board'] ?? null);
        if (!$board) {
            return $this->fail('mxboard_err_board_not_found');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return $this->fail('mxboard_err_title_required');
        }

        $column = $this->columnBy($board, ['is_initial' => true]);
        if (!$column) {
            return $this->fail('mxboard_err_no_initial_column');
        }

        $now = time();

        /** @var MxBoardTask $task */
        $task = $this->modx->newObject(MxBoardTask::class);
        $task->fromArray([
            'board_id' => (int) $board->get('id'),
            'column_id' => (int) $column->get('id'),
            'title' => $title,
            'tor' => (string) ($data['tor'] ?? ''),
            'author_id' => (int) $user->get('id'),
            'assignee_id' => 0,
            'priority' => (int) ($data['priority'] ?? 0),
            'rank' => $this->nextRank((int) $column->get('id')),
            'meta' => $this->decodeMeta($data['meta'] ?? null),
            'createdon' => $now,
            'updatedon' => $now,
        ]);

        if (!$task->save()) {
            return $this->fail('mxboard_err_save');
        }

        $this->log($task, $user, 'create', '', (string) $column->get('key'), '', $channel);
        $this->fireEvent('mxbOnTaskCreate', $task, $user, ['channel' => $channel]);

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

        $board = $this->modx->getObject(MxBoardBoard::class, (int) $task->get('board_id'));
        $current = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
        if (!$board || !$current) {
            return $this->fail('mxboard_err_task_not_found');
        }

        // Брать можно только из колонки «готово к работе».
        if (!(bool) $current->get('is_ready')) {
            return $this->fail('mxboard_err_not_ready');
        }

        $target = $this->nextColumnAfter($board, $current);
        if (!$target) {
            return $this->fail('mxboard_err_no_next_column');
        }

        $userId = (int) $user->get('id');

        $limit = (int) $this->modx->getOption('mxboard.wip_limit', null, 0);
        if ($limit > 0 && $this->inProgressCount($board, $userId) >= $limit) {
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
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function move(modUser $user, int $taskId, string $columnKey, string $note = '', string $channel = 'mgr'): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        $board = $this->modx->getObject(MxBoardBoard::class, (int) $task->get('board_id'));
        $current = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
        if (!$board) {
            return $this->fail('mxboard_err_board_not_found');
        }

        $target = $this->columnBy($board, ['key' => $columnKey]);
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

        $this->fireEvent('mxbOnBeforeTaskMove', $task, $user, [
            'channel' => $channel,
            'from' => $current ? (string) $current->get('key') : '',
            'to' => (string) $target->get('key'),
        ]);

        $now = time();
        $isFinal = (bool) $target->get('is_final');

        $task->set('column_id', (int) $target->get('id'));
        $task->set('rank', $this->nextRank((int) $target->get('id')));
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
        $isSuperuser = (bool) $user->get('sudo') || $this->modx->hasPermission(Transitions::PERMISSION_MOVE_ANY);

        if (!$isAssignee && !$isSuperuser) {
            return $this->fail('mxboard_err_move_denied');
        }

        $board = $this->modx->getObject(MxBoardBoard::class, (int) $task->get('board_id'));
        $current = $this->modx->getObject(MxBoardColumn::class, (int) $task->get('column_id'));
        $ready = $board ? $this->columnBy($board, ['is_ready' => true]) : null;
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
     * Комментарий к карточке — как агенты отчитываются о ходе работы.
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

    /** Доска по ключу; без ключа — из системной настройки. */
    public function resolveBoard(?string $key = null): ?MxBoardBoard
    {
        $key = $key ?: (string) $this->modx->getOption('mxboard.default_board', null, 'default');

        /** @var MxBoardBoard|null $board */
        $board = $this->modx->getObject(MxBoardBoard::class, ['key' => $key, 'active' => true]);

        return $board;
    }

    /**
     * Колонка доски по произвольному критерию.
     *
     * @param array<string, mixed> $criteria
     */
    public function columnBy(MxBoardBoard $board, array $criteria): ?MxBoardColumn
    {
        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(
            MxBoardColumn::class,
            array_merge(['board_id' => (int) $board->get('id')], $criteria)
        );

        return $column;
    }

    /** Следующая по порядку колонка (куда карточка едет при захвате). */
    private function nextColumnAfter(MxBoardBoard $board, MxBoardColumn $current): ?MxBoardColumn
    {
        $c = $this->modx->newQuery(MxBoardColumn::class);
        $c->where([
            'board_id' => (int) $board->get('id'),
            'rank:>' => (int) $current->get('rank'),
        ]);
        $c->sortby('rank', 'ASC');
        $c->limit(1);

        /** @var MxBoardColumn|null $column */
        $column = $this->modx->getObject(MxBoardColumn::class, $c);

        return $column;
    }

    /** Сколько карточек уже в работе у пользователя (для wip_limit). */
    private function inProgressCount(MxBoardBoard $board, int $userId): int
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->innerJoin(MxBoardColumn::class, 'Column');
        $c->where([
            'MxBoardTask.board_id' => (int) $board->get('id'),
            'MxBoardTask.assignee_id' => $userId,
            'Column.is_final' => false,
            'MxBoardTask.startedon:>' => 0,
        ]);

        return (int) $this->modx->getCount(MxBoardTask::class, $c);
    }

    private function nextRank(int $columnId): int
    {
        $c = $this->modx->newQuery(MxBoardTask::class);
        $c->where(['column_id' => $columnId]);

        return (int) $this->modx->getCount(MxBoardTask::class, $c);
    }

    /** @return array<string, mixed>|null */
    private function decodeMeta(mixed $meta): ?array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);

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
