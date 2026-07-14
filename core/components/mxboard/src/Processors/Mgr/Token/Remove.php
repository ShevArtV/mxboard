<?php

declare(strict_types=1);

namespace MxBoard\Processors\Mgr\Token;

use MODX\Revolution\Processors\Model\RemoveProcessor;
use MxBoard\Model\MxBoardToken;

/**
 * Отозвать токен агента: запись удаляется целиком — восстановить нечего,
 * в базе всё равно только хэш.
 */
class Remove extends RemoveProcessor
{
    public $classKey = MxBoardToken::class;
    public $languageTopics = ['mxboard:default'];
    public $objectType = 'mxboard.token';
    public $checkRemovePermission = false;
}
