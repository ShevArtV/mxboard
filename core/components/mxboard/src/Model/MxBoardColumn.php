<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardColumn
 *
 * @property integer $project_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property integer $position
 * @property string $move_roles
 * @property string $color
 * @property boolean $is_initial
 * @property boolean $is_final
 * @property boolean $is_start
 * @property integer $createdon
 *
 * @property \MxBoard\Model\MxBoardProject $Project
 * @property \MxBoard\Model\MxBoardTask[] $Tasks
 *
 * @package MxBoard\Model
 */
class MxBoardColumn extends \xPDO\Om\xPDOSimpleObject
{
}
