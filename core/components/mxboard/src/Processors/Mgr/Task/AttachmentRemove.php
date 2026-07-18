<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Task;

use MODX\Revolution\modUser;
use MxBoard\Processors\Mgr\ServiceProcessor;
use MxBoard\Service\AttachmentService;

/**
 * Удаление одного вложения (запись + физфайл). Право — в AttachmentService.
 */
class AttachmentRemove extends ServiceProcessor
{
    protected function handle(modUser $user)
    {
        return $this->fromResult((new AttachmentService($this->modx))->delete(
            $user,
            (int) $this->getProperty('attachment_id', 0)
        ));
    }
}
