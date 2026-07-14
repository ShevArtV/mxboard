<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardColumn
 *
 * @property integer $board_id
 * @property string $key
 * @property string $name
 * @property integer $position
 * @property string $move_roles
 * @property boolean $is_initial
 * @property boolean $is_ready
 * @property boolean $is_final
 * @property integer $createdon
 *
 * @property \MxBoard\Model\MxBoardTask[] $Tasks
 *
 * @package MxBoard\Model
 */
class MxBoardColumn extends \xPDO\Om\xPDOSimpleObject
{
}
