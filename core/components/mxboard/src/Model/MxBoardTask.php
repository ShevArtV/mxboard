<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardTask
 *
 * @property integer $board_id
 * @property integer $column_id
 * @property string $title
 * @property string $tor
 * @property integer $author_id
 * @property integer $assignee_id
 * @property integer $position
 * @property integer $priority
 * @property array $meta
 * @property integer $createdon
 * @property integer $updatedon
 * @property integer $startedon
 * @property integer $closedon
 *
 * @property \MxBoard\Model\MxBoardComment[] $Comments
 * @property \MxBoard\Model\MxBoardLog[] $Logs
 *
 * @package MxBoard\Model
 */
class MxBoardTask extends \xPDO\Om\xPDOSimpleObject
{
}
