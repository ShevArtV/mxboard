<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MxBoard\Model\MxBoardNotification;
use MxBoard\Model\MxBoardTask;

/**
 * Материализация in-app уведомлений в очередь mxboard_notification.
 *
 * Вызывается из TaskService::fireEvent в ЕДИНСТВЕННОЙ точке — после invokeEvent, — чтобы
 * не размазывать генерацию по всем методам сервиса. Само событие MODX остаётся точкой
 * интеграции для внешних систем (Jarvis-поллер читает mxboard_log, не эту таблицу);
 * здесь — только браузерные уведомления, которые стримит sse.php.
 *
 * Получатели любого события — участники задачи (автор + исполнитель) за вычетом актора:
 * тот, кто сделал действие, себе уведомление не пишет.
 */
final class NotificationService
{
    /**
     * Событие MODX → тип уведомления. События вне карты уведомлений не порождают
     * (BeforeTaskMove/Update/Delete/Take/Release, а также Close — его покрывает Move).
     */
    private const EVENT_TYPES = [
        'mxbOnTaskCreate' => 'create',
        'mxbOnTaskMove' => 'move',
        'mxbOnTaskComment' => 'comment',
        'mxbOnDeadlineDispute' => 'deadline_dispute',
        'mxbOnDeadlineResolve' => 'deadline_resolve',
        'mxbOnPlanDispute' => 'plan_dispute',
        'mxbOnPlanResolve' => 'plan_resolve',
    ];

    public function __construct(private modX $modx)
    {
    }

    /**
     * Записать уведомления по событию. Тихо выходит, если событие не уведомляемое,
     * фича выключена или получателей нет.
     *
     * @param array<string, mixed> $extra доп. поля события (from, to, comment)
     */
    public function emit(string $event, MxBoardTask $task, modUser $actor, array $extra = []): void
    {
        if (!(bool) $this->modx->getOption('mxboard.sse_enabled', null, true)) {
            return;
        }

        $type = self::EVENT_TYPES[$event] ?? '';
        if ($type === '') {
            return;
        }

        $actorId = (int) $actor->get('id');
        $recipients = [];
        foreach ([(int) $task->get('author_id'), (int) $task->get('assignee_id')] as $uid) {
            if ($uid > 0 && $uid !== $actorId && !in_array($uid, $recipients, true)) {
                $recipients[] = $uid;
            }
        }
        if (!$recipients) {
            return;
        }

        $payload = $this->payload($task, $type, $extra);
        $now = time();

        foreach ($recipients as $uid) {
            /** @var MxBoardNotification $n */
            $n = $this->modx->newObject(MxBoardNotification::class);
            $n->fromArray([
                'user_id' => $uid,
                'actor_id' => $actorId,
                'task_id' => (int) $task->get('id'),
                'type' => $type,
                'payload' => $payload,
                'seen' => 0,
                'createdon' => $now,
            ]);
            if (!$n->save()) {
                $this->modx->log(modX::LOG_LEVEL_ERROR, '[mxBoard] Не удалось записать уведомление: task#' . (int) $task->get('id') . ' user#' . $uid);
            }
        }
    }

    /**
     * Лента уведомлений получателя (новые сверху) + число непрочитанных.
     *
     * @return array{items: list<array<string, mixed>>, unseen: int}
     */
    public function listFor(int $userId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return ['items' => [], 'unseen' => 0];
        }

        $c = $this->modx->newQuery(MxBoardNotification::class);
        $c->leftJoin(modUser::class, 'Actor', 'Actor.id = MxBoardNotification.actor_id');
        $c->where(['MxBoardNotification.user_id' => $userId]);
        $c->select([
            'id' => 'MxBoardNotification.id',
            'type' => 'MxBoardNotification.type',
            'task_id' => 'MxBoardNotification.task_id',
            'actor_id' => 'MxBoardNotification.actor_id',
            'payload' => 'MxBoardNotification.payload',
            'seen' => 'MxBoardNotification.seen',
            'createdon' => 'MxBoardNotification.createdon',
            'actor' => 'Actor.username',
        ]);
        $c->sortby('MxBoardNotification.id', 'DESC');
        $c->limit(max(1, min(200, $limit)));

        $items = [];
        $c->prepare();
        if ($c->stmt && $c->stmt->execute()) {
            foreach ((array) $c->stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $items[] = $this->hydrate($row);
            }
        }

        return [
            'items' => $items,
            'unseen' => (int) $this->modx->getCount(MxBoardNotification::class, ['user_id' => $userId, 'seen' => 0]),
        ];
    }

    /**
     * Новые уведомления пользователя с курсора (id по возрастанию) — для SSE-стрима.
     *
     * @return list<array<string, mixed>>
     */
    public function sinceId(int $userId, int $lastId, int $limit = 50): array
    {
        if ($userId <= 0) {
            return [];
        }

        $c = $this->modx->newQuery(MxBoardNotification::class);
        $c->leftJoin(modUser::class, 'Actor', 'Actor.id = MxBoardNotification.actor_id');
        $c->where(['MxBoardNotification.user_id' => $userId, 'MxBoardNotification.id:>' => $lastId]);
        $c->select([
            'id' => 'MxBoardNotification.id',
            'type' => 'MxBoardNotification.type',
            'task_id' => 'MxBoardNotification.task_id',
            'actor_id' => 'MxBoardNotification.actor_id',
            'payload' => 'MxBoardNotification.payload',
            'seen' => 'MxBoardNotification.seen',
            'createdon' => 'MxBoardNotification.createdon',
            'actor' => 'Actor.username',
        ]);
        $c->sortby('MxBoardNotification.id', 'ASC');
        $c->limit(max(1, min(200, $limit)));

        $out = [];
        $c->prepare();
        if ($c->stmt && $c->stmt->execute()) {
            foreach ((array) $c->stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $out[] = $this->hydrate($row);
            }
        }

        return $out;
    }

    /**
     * Отметить прочитанными. Пустой $ids — все непрочитанные пользователя. Чужие строки
     * не трогает (условие user_id). Возвращает число затронутых.
     *
     * @param list<int> $ids
     */
    public function markSeen(int $userId, array $ids = []): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $where = ['user_id' => $userId, 'seen' => 0];
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $v): bool => $v > 0));
        if ($ids) {
            $where['id:IN'] = $ids;
        }

        $affected = $this->modx->updateCollection(MxBoardNotification::class, ['seen' => 1], $where);

        return is_numeric($affected) ? (int) $affected : 0;
    }

    /**
     * Строка выборки → элемент фронта (payload распакован из JSON).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function hydrate(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'task_id' => (int) $row['task_id'],
            'actor_id' => (int) ($row['actor_id'] ?? 0),
            'actor' => (string) ($row['actor'] ?? ''),
            'payload' => is_array($payload) ? $payload : [],
            'seen' => (bool) $row['seen'],
            'createdon' => (int) $row['createdon'],
        ];
    }

    /**
     * JSON-полезная нагрузка для фронта: чего хватает на строку тоста/списка без
     * дозагрузки задачи.
     */
    private function payload(MxBoardTask $task, string $type, array $extra): string
    {
        $data = [
            'num' => (string) $task->get('num'),
            'title' => (string) $task->get('title'),
        ];

        if ($type === 'move') {
            $data['from'] = (string) ($extra['from'] ?? '');
            $data['to'] = (string) ($extra['to'] ?? '');
        }
        if ($type === 'comment') {
            $data['preview'] = mb_substr(trim((string) ($extra['comment'] ?? '')), 0, 140);
        }

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
