<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardTask
 *
 * @property integer $project_id
 * @property integer $parent_id
 * @property integer $type_id
 * @property integer $column_id
 * @property string $title
 * @property string $tor
 * @property integer $author_id
 * @property integer $assignee_id
 * @property integer $priority
 * @property integer $position
 * @property integer $deadlineon
 * @property boolean $deadline_disputed
 * @property integer $deadline_proposed
 * @property array $fields
 * @property array $meta
 * @property integer $createdon
 * @property integer $updatedon
 * @property integer $startedon
 * @property integer $closedon
 *
 * @property \MxBoard\Model\MxBoardProject $Project
 * @property \MxBoard\Model\MxBoardColumn $Column
 * @property \MxBoard\Model\MxBoardTaskType $Type
 * @property \MxBoard\Model\MxBoardTask $Parent
 * @property \MxBoard\Model\MxBoardTask[] $Subtasks
 * @property \MxBoard\Model\MxBoardComment[] $Comments
 * @property \MxBoard\Model\MxBoardLog[] $Logs
 *
 * @package MxBoard\Model
 */
class MxBoardTask extends \xPDO\Om\xPDOSimpleObject
{
}
