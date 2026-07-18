<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardCounter
 *
 * Атомарный счётчик номеров задач по периоду (см. TaskService::nextCounter).
 *
 * @property string $period
 * @property integer $value
 *
 * @package MxBoard\Model
 */
class MxBoardCounter extends \xPDO\Om\xPDOSimpleObject
{
}
