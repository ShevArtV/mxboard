<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Processor;
use MxBoard\Helpers\Transitions;
use MxBoard\Model\MxBoardTask;

/**
 * Правка карточки: title, tor, priority, meta.
 *
 * column_id и assignee_id здесь НЕ меняются сознательно: движение карточки —
 * только через Move/Take/Release, где работают правила переходов и пишется журнал.
 * Разреши правку этих полей здесь — и любой агент обойдёт правила обычным Update.
 */
class Update extends Processor
{
    public $languageTopics = ['mxboard:default'];

    public function process()
    {
        $this->modx->lexicon->load('mxboard:default');

        $user = $this->modx->user;
        if (!$user instanceof modUser) {
            return $this->failure($this->modx->lexicon('mxboard_err_unauthenticated'));
        }

        $id = (int) $this->getProperty('id', 0);

        /** @var MxBoardTask|null $task */
        $task = $id > 0 ? $this->modx->getObject(MxBoardTask::class, $id) : null;
        if (!$task) {
            return $this->failure($this->modx->lexicon('mxboard_err_task_not_found'));
        }

        $isAuthor = (int) $user->get('id') === (int) $task->get('author_id');
        $isSuperuser = (bool) $user->get('sudo') || $this->modx->hasPermission(Transitions::PERMISSION_MOVE_ANY);
        if (!$isAuthor && !$isSuperuser) {
            return $this->failure($this->modx->lexicon('mxboard_err_edit_denied'));
        }

        if ($this->getProperty('title') !== null) {
            $title = trim((string) $this->getProperty('title'));
            if ($title === '') {
                return $this->failure($this->modx->lexicon('mxboard_err_title_required'));
            }
            $task->set('title', $title);
        }

        if ($this->getProperty('tor') !== null) {
            $task->set('tor', (string) $this->getProperty('tor'));
        }

        if ($this->getProperty('priority') !== null) {
            $task->set('priority', (int) $this->getProperty('priority'));
        }

        $meta = $this->getProperty('meta');
        if ($meta !== null) {
            $task->set('meta', $this->decodeMeta($meta));
        }

        $task->set('updatedon', time());

        if (!$task->save()) {
            return $this->failure($this->modx->lexicon('mxboard_err_save'));
        }

        return $this->success('', $task->toArray());
    }

    /**
     * meta приходит и объектом (JSON-тело), и строкой (form-data) — нормализуем.
     *
     * @return array<string, mixed>|null
     */
    protected function decodeMeta(mixed $meta): ?array
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
}
