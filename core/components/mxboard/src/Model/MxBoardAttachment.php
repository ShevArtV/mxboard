<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardAttachment
 *
 * @property integer $task_id
 * @property integer $comment_id
 * @property integer $user_id
 * @property string $name
 * @property string $path
 * @property string $url
 * @property integer $size
 * @property string $ext
 * @property string $mime
 * @property integer $createdon
 *
 * @package MxBoard\Model
 */
class MxBoardAttachment extends \xPDO\Om\xPDOSimpleObject
{
}
