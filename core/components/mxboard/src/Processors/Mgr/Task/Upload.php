<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\AttachmentService;

/**
 * Загрузка вложений к задаче (comment_id=0) или к сообщению чата (comment_id>0).
 *
 * Файлы приходят multipart в $_FILES (useApi их не шлёт — фронт в подзадаче C грузит
 * raw fetch'ем), поэтому берём их напрямую, а не из свойств процессора. Право/лимиты/
 * заливка — в AttachmentService.
 */
class Upload extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        $taskId = (int) $this->getProperty('task_id', $this->getProperty('id', 0));
        $commentId = (int) $this->getProperty('comment_id', 0);

        return $this->fromResult((new AttachmentService($this->modx))->upload(
            $user,
            $taskId,
            $commentId,
            $this->collectFiles()
        ));
    }

    /**
     * Нормализовать $_FILES в плоский список файлов независимо от того, пришёл один файл
     * (`file`) или несколько (`file[]`).
     *
     * @return list<array{name: string, tmp_name: string, size: int, error: int, type: string}>
     */
    private function collectFiles(): array
    {
        $out = [];
        foreach ($_FILES as $field) {
            if (is_array($field['name'] ?? null)) {
                foreach ($field['name'] as $i => $name) {
                    $out[] = [
                        'name' => (string) $name,
                        'tmp_name' => (string) ($field['tmp_name'][$i] ?? ''),
                        'size' => (int) ($field['size'][$i] ?? 0),
                        'error' => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                        'type' => (string) ($field['type'][$i] ?? ''),
                    ];
                }
                continue;
            }
            $out[] = [
                'name' => (string) ($field['name'] ?? ''),
                'tmp_name' => (string) ($field['tmp_name'] ?? ''),
                'size' => (int) ($field['size'] ?? 0),
                'error' => (int) ($field['error'] ?? UPLOAD_ERR_NO_FILE),
                'type' => (string) ($field['type'] ?? ''),
            ];
        }

        return $out;
    }
}
