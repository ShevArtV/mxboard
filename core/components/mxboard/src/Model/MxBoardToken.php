<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardToken
 *
 * @property integer $user_id
 * @property string $name
 * @property string $token_hash
 * @property boolean $active
 * @property integer $lastusedon
 * @property integer $createdon
 *
 * @package MxBoard\Model
 */
class MxBoardToken extends \xPDO\Om\xPDOSimpleObject
{
}
