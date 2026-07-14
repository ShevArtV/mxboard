<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MODX\Revolution\Processors\Processor;
use MxBoard\Service\TaskService;

/**
 * Отпустить карточку: снять исполнителя и вернуть её в ready-колонку.
 */
class Release extends Processor
{
    public $languageTopics = ['mxboard:default'];

    public function process()
    {
        $this->modx->lexicon->load('mxboard:default');

        $user = $this->modx->user;
        if (!$user instanceof modUser) {
            return $this->failure($this->modx->lexicon('mxboard_err_unauthenticated'));
        }

        $result = (new TaskService($this->modx))->release(
            $user,
            (int) $this->getProperty('id', 0),
            (string) $this->getProperty('note', ''),
            'mgr'
        );

        if (!$result['success']) {
            return $this->failure($result['message']);
        }

        return $this->success($result['message'], $result['object']);
    }
}
