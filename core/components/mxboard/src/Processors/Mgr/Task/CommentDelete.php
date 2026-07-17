<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\TaskService;

class CommentDelete extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new TaskService($this->modx))->deleteComment(
            $user,
            (int) $this->getProperty('comment_id', 0),
            'mgr'
        ));
    }
}
