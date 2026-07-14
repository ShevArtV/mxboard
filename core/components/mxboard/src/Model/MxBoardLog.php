<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardLog
 *
 * @property integer $task_id
 * @property integer $user_id
 * @property string $action
 * @property string $from_column
 * @property string $to_column
 * @property string $note
 * @property string $channel
 * @property integer $createdon
 *
 * @package MxBoard\Model
 */
class MxBoardLog extends \xPDO\Om\xPDOSimpleObject
{
}
