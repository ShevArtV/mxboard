<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardProject
 *
 * @property integer $department_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property boolean $active
 * @property integer $position
 * @property integer $createdon
 * @property integer $updatedon
 *
 * @property \MxBoard\Model\MxBoardColumn[] $Columns
 * @property \MxBoard\Model\MxBoardTask[] $Tasks
 *
 * @package MxBoard\Model
 */
class MxBoardProject extends \xPDO\Om\xPDOSimpleObject
{
}
