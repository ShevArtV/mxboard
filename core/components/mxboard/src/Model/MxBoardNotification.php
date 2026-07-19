<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardNotification
 *
 * @property integer $user_id  получатель
 * @property integer $actor_id кто вызвал событие
 * @property integer $task_id
 * @property string  $type     create | move | comment | close | deadline_dispute | deadline_resolve
 * @property string  $payload  JSON: num, title, from, to, note
 * @property boolean $seen
 * @property integer $createdon
 *
 * @package MxBoard\Model
 */
class MxBoardNotification extends \xPDO\Om\xPDOSimpleObject
{
}
