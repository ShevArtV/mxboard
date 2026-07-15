<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardDepartment
 *
 * @property integer $usergroup_id
 * @property string $name
 * @property boolean $active
 * @property integer $position
 * @property integer $createdon
 *
 * @property \MxBoard\Model\MxBoardProject[] $Projects
 * @property \MxBoard\Model\MxBoardTaskType[] $Types
 *
 * @package MxBoard\Model
 */
class MxBoardDepartment extends \xPDO\Om\xPDOSimpleObject
{
}
