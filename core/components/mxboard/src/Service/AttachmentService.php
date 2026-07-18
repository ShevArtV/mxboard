<?php

declare(strict_types=1);

namespace MxBoard\Service;

use MODX\Revolution\modUser;
use MODX\Revolution\modX;
use MODX\Revolution\Sources\modMediaSource;
use MxBoard\Helpers\Transitions;
use MxBoard\Helpers\Visibility;
use MxBoard\Model\MxBoardAttachment;
use MxBoard\Model\MxBoardComment;
use MxBoard\Model\MxBoardTask;

/**
 * Вложения задач и сообщений чата: заливка/удаление/чтение через выделенный media source.
 *
 * Общий для всех каналов (менеджер/REST/MCP) — как TaskService: правила видимости и
 * права не должны зависеть от канала. Физические файлы живут в источнике
 * mxboard.media_source; в mxboard_attachment — только метаданные + путь/URL.
 *
 * Каскад: DB-записи вложений задачи снимаются composite-связью MxBoardTask→Attachments,
 * но физфайлы удаляются ТОЛЬКО явным purgeForTask/purgeForComment — сам remove() их не трёт.
 */
class AttachmentService
{
    /** Расширения, которые фронт показывает превью, а не чипом. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];

    public function __construct(private modX $modx)
    {
    }

    /**
     * Залить файлы к задаче (comment_id = 0) или к сообщению чата (comment_id > 0).
     *
     * Право — как на просмотр задачи (canView): кто видит задачу, тот может к ней приложить
     * файл. Каждый файл проверяется на размер/расширение, затем грузится в источник в
     * подпапку task-<id>/, создаётся запись вложения.
     *
     * @param list<array{name: string, tmp_name: string, size: int, error: int, type?: string}> $files
     *
     * @return array{success: bool, message: string, object: array<string, mixed>|null}
     */
    public function upload(modUser $user, int $taskId, int $commentId, array $files): array
    {
        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, $taskId);
        if (!$task) {
            return $this->fail('mxboard_err_task_not_found');
        }

        if (!Visibility::canView($this->modx, $user, $task)) {
            return $this->fail('mxboard_err_view_denied');
        }

        // Вложение сообщения: коммент обязан существовать и принадлежать этой задаче.
        if ($commentId > 0) {
            /** @var MxBoardComment|null $comment */
            $comment = $this->modx->getObject(MxBoardComment::class, $commentId);
            if (!$comment || (int) $comment->get('task_id') !== $taskId) {
                return $this->fail('mxboard_err_comment_not_found');
            }
        }

        if (empty($files)) {
            return $this->fail('mxboard_err_upload_no_file');
        }

        $source = $this->resolveSource();
        if (!$source) {
            return $this->fail('mxboard_err_source_unavailable');
        }

        $maxSize = (int) $this->modx->getOption('mxboard.upload_max_size', null, 0);
        $allowedExt = $this->allowedExtensions();
        $container = 'task-' . $taskId . '/';

        $created = [];
        $errors = [];
        foreach ($files as $file) {
            $name = trim((string) ($file['name'] ?? ''));
            if ($name === '' || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
                $errors[] = $this->lex('mxboard_err_upload_failed') . ($name !== '' ? ": {$name}" : '');
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!empty($allowedExt) && !in_array($ext, $allowedExt, true)) {
                $errors[] = $this->lex('mxboard_err_upload_ext') . ": {$name}";
                continue;
            }

            $size = (int) ($file['size'] ?? 0);
            if ($maxSize > 0 && $size > $maxSize) {
                $errors[] = $this->lex('mxboard_err_upload_size') . ": {$name}";
                continue;
            }

            // Имя приводим к ascii-safe + короткий хэш: избегаем коллизий и сюрпризов
            // от upload_translit (иначе итоговый путь мог бы разойтись с нашим).
            $safe = $this->safeName($name, $ext);
            $upload = [
                'name' => $safe,
                'tmp_name' => $file['tmp_name'],
                'size' => $size,
                'error' => UPLOAD_ERR_OK,
                'type' => (string) ($file['type'] ?? ''),
            ];

            $before = count($source->getErrors());
            $source->uploadObjectsToContainer($container, [$upload]);
            if (count($source->getErrors()) > $before) {
                $srcErrors = $source->getErrors();
                $errors[] = $this->lex('mxboard_err_upload_failed') . ": {$name} — " . implode('; ', array_slice($srcErrors, $before));
                continue;
            }

            $path = $container . $safe;
            $url = (string) ($source->getObjectUrl($path) ?: '');

            /** @var MxBoardAttachment $attachment */
            $attachment = $this->modx->newObject(MxBoardAttachment::class);
            $attachment->fromArray([
                'task_id' => $taskId,
                'comment_id' => $commentId,
                'user_id' => (int) $user->get('id'),
                'name' => mb_substr($name, 0, 255),
                'path' => $path,
                'url' => $url,
                'size' => $size,
                'ext' => mb_substr($ext, 0, 20),
                'mime' => mb_substr((string) ($file['type'] ?? ''), 0, 100),
                'createdon' => time(),
            ]);
            if (!$attachment->save()) {
                // Запись не легла — не оставляем осиротевший файл в источнике.
                $source->removeObject($path);
                $errors[] = $this->lex('mxboard_err_save') . ": {$name}";
                continue;
            }

            $created[] = $this->toArray($attachment);
        }

        if (empty($created)) {
            return [
                'success' => false,
                'message' => !empty($errors) ? implode('; ', $errors) : $this->lex('mxboard_err_upload_failed'),
                'object' => null,
            ];
        }

        return [
            'success' => true,
            // Частичный успех: часть файлов могла не пройти — отдаём и предупреждение.
            'message' => !empty($errors) ? implode('; ', $errors) : '',
            'object' => ['attachments' => $created],
        ];
    }

    /**
     * Удалить одно вложение (запись + физфайл).
     *
     * Право: автор вложения (для файла задачи) / автор коммента (для файла сообщения) /
     * автор задачи / менеджер.
     *
     * @return array{success: bool, message: string, object: null}
     */
    public function delete(modUser $user, int $attachmentId): array
    {
        /** @var MxBoardAttachment|null $attachment */
        $attachment = $this->modx->getObject(MxBoardAttachment::class, $attachmentId);
        if (!$attachment) {
            return $this->fail('mxboard_err_attachment_not_found');
        }

        /** @var MxBoardTask|null $task */
        $task = $this->modx->getObject(MxBoardTask::class, (int) $attachment->get('task_id'));
        if (!$task) {
            // Задачи нет (рассинхрон) — вложение всё равно чистим.
            $this->removeFile((string) $attachment->get('path'));
            $attachment->remove();

            return ['success' => true, 'message' => '', 'object' => null];
        }

        if (!$this->canRemove($user, $task, $attachment)) {
            return $this->fail('mxboard_err_attachment_denied');
        }

        $this->removeFile((string) $attachment->get('path'));
        if (!$attachment->remove()) {
            return $this->fail('mxboard_err_remove_failed');
        }

        return ['success' => true, 'message' => '', 'object' => null];
    }

    /**
     * Снести физфайлы + записи всех вложений одного комментария. Зовётся из
     * TaskService::deleteComment ДО удаления коммента.
     */
    public function purgeForComment(int $commentId): void
    {
        if ($commentId <= 0) {
            return;
        }
        $this->purge(['comment_id' => $commentId]);
    }

    /**
     * Снести физфайлы + записи ВСЕХ вложений задачи (уровня задачи и всех её комментов).
     * Зовётся из TaskService::delete ДО удаления задачи (composite снял бы только записи).
     */
    public function purgeForTask(int $taskId): void
    {
        if ($taskId <= 0) {
            return;
        }
        $this->purge(['task_id' => $taskId]);
    }

    /**
     * Вложения задачи для чтения (BoardQuery::taskDetail): плоский список массивов
     * с is_image. Разбивку на «задачные» (comment_id=0) и по комментам делает вызывающий.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTask(int $taskId): array
    {
        $c = $this->modx->newQuery(MxBoardAttachment::class);
        $c->where(['task_id' => $taskId]);
        $c->sortby('createdon', 'ASC');
        $c->sortby('id', 'ASC');

        $out = [];
        /** @var MxBoardAttachment $attachment */
        foreach ($this->modx->getCollection(MxBoardAttachment::class, $c) as $attachment) {
            $out[] = $this->toArray($attachment);
        }

        return $out;
    }

    /** Картинка ли (для выбора превью vs чип) — по расширению. */
    public static function isImage(string $ext): bool
    {
        return in_array(strtolower($ext), self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Удалить набор вложений по критерию: сначала физфайлы из источника, затем записи.
     *
     * @param array<string, mixed> $criteria
     */
    private function purge(array $criteria): void
    {
        $c = $this->modx->newQuery(MxBoardAttachment::class);
        $c->where($criteria);
        /** @var MxBoardAttachment[] $attachments */
        $attachments = $this->modx->getCollection(MxBoardAttachment::class, $c);
        if (empty($attachments)) {
            return;
        }

        $source = $this->resolveSource();
        foreach ($attachments as $attachment) {
            $path = (string) $attachment->get('path');
            if ($source && $path !== '') {
                try {
                    $source->removeObject($path);
                } catch (\Throwable $e) {
                    $this->modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] Не удалось удалить файл вложения ' . $path . ': ' . $e->getMessage());
                }
            }
            $attachment->remove();
        }
    }

    /** Удалить один физфайл из источника (ошибки не фатальны — запись всё равно уйдёт). */
    private function removeFile(string $path): void
    {
        if ($path === '') {
            return;
        }
        $source = $this->resolveSource();
        if (!$source) {
            return;
        }
        try {
            $source->removeObject($path);
        } catch (\Throwable $e) {
            $this->modx->log(modX::LOG_LEVEL_WARN, '[mxBoard] Не удалось удалить файл вложения ' . $path . ': ' . $e->getMessage());
        }
    }

    /** Право на удаление конкретного вложения. */
    private function canRemove(modUser $user, MxBoardTask $task, MxBoardAttachment $attachment): bool
    {
        $userId = (int) $user->get('id');

        if ($userId === (int) $task->get('author_id')) {
            return true;
        }
        if (Transitions::isManager($this->modx, $user, $task)) {
            return true;
        }

        $commentId = (int) $attachment->get('comment_id');
        if ($commentId > 0) {
            /** @var MxBoardComment|null $comment */
            $comment = $this->modx->getObject(MxBoardComment::class, $commentId);

            return $comment && $userId === (int) $comment->get('user_id');
        }

        // Вложение уровня задачи: снять может тот, кто загрузил.
        return $userId === (int) $attachment->get('user_id');
    }

    /**
     * Media source вложений: mxboard.media_source, с фолбэком на дефолтный источник MODX,
     * если настройка пуста или источник исчез.
     */
    private function resolveSource(): ?modMediaSource
    {
        $id = (int) $this->modx->getOption('mxboard.media_source', null, 0);
        /** @var modMediaSource|null $source */
        $source = modMediaSource::getDefaultSource($this->modx, $id > 0 ? $id : null);
        if (!$source) {
            return null;
        }
        $source->initialize();

        return $source;
    }

    /**
     * Ascii-safe уникальное имя файла: слаг базового имени + короткий хэш + расширение.
     * Хэш детерминирован от имени и времени, но без Math.random — коллизии в пределах
     * подпапки задачи практически исключены.
     */
    private function safeName(string $original, string $ext): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $slug = $this->modx->filterPathSegment($base);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $slug), '-');
        if ($slug === '') {
            $slug = 'file';
        }
        $slug = mb_substr($slug, 0, 80);
        $hash = substr(hash('crc32b', $original . '|' . microtime(true) . '|' . uniqid('', true)), 0, 8);

        return $ext !== '' ? "{$slug}-{$hash}.{$ext}" : "{$slug}-{$hash}";
    }

    /** Разрешённые расширения из настройки (пусто — не ограничиваем на стороне mxBoard). */
    private function allowedExtensions(): array
    {
        $raw = trim((string) $this->modx->getOption('mxboard.upload_extensions', null, ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($e) => strtolower(trim((string) $e)),
            explode(',', $raw)
        )));
    }

    /** @return array<string, mixed> */
    private function toArray(MxBoardAttachment $attachment): array
    {
        $ext = (string) $attachment->get('ext');

        return [
            'id' => (int) $attachment->get('id'),
            'task_id' => (int) $attachment->get('task_id'),
            'comment_id' => (int) $attachment->get('comment_id'),
            'user_id' => (int) $attachment->get('user_id'),
            'name' => (string) $attachment->get('name'),
            'url' => (string) $attachment->get('url'),
            'size' => (int) $attachment->get('size'),
            'ext' => $ext,
            'mime' => (string) $attachment->get('mime'),
            'is_image' => self::isImage($ext),
            'createdon' => (int) $attachment->get('createdon'),
        ];
    }

    /** @return array{success: bool, message: string, object: null} */
    private function fail(string $lexiconKey): array
    {
        return ['success' => false, 'message' => $this->lex($lexiconKey), 'object' => null];
    }

    private function lex(string $key): string
    {
        return $this->modx->lexicon($key) ?: $key;
    }
}
