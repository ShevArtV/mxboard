<?php
namespace MxBoard\Model;

use xPDO\xPDO;

/**
 * Class MxBoardPriority
 *
 * Глобальный справочник приоритетов задачи (проектно независимый). Числовое значение
 * `value` пишется в mxboard_task.priority; `name`/`color` — как приоритет показывается.
 * Уникальность `value` и `name` — на уровне БД (индексы), см. mysql-схему.
 *
 * @property string  $name
 * @property string  $color
 * @property integer $value
 * @property integer $createdon
 *
 * @package MxBoard\Model
 */
class MxBoardPriority extends \xPDO\Om\xPDOSimpleObject
{
}
