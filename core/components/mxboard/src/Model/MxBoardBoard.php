<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardBoard
 *
 * @property string $key
 * @property string $name
 * @property string $description
 * @property boolean $active
 * @property integer $createdon
 * @property integer $updatedon
 *
 * @property \MxBoard\Model\MxBoardColumn[] $Columns
 * @property \MxBoard\Model\MxBoardTask[] $Tasks
 *
 * @package MxBoard\Model
 */
class MxBoardBoard extends \xPDO\Om\xPDOSimpleObject
{
}
