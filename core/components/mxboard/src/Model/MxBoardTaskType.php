<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardTaskType
 *
 * @property integer $department_id
 * @property string $key
 * @property string $name
 * @property string $description
 * @property boolean $active
 * @property integer $position
 * @property integer $createdon
 *
 * @property \MxBoard\Model\MxBoardField[] $Fields
 *
 * @package MxBoard\Model
 */
class MxBoardTaskType extends \xPDO\Om\xPDOSimpleObject
{
}
