<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

class CommentUpdate extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new TaskService($this->modx))->updateComment(
            $user,
            (int) $this->getProperty('comment_id', 0),
            (string) $this->getProperty('content', ''),
            'mgr'
        ));
    }
}
