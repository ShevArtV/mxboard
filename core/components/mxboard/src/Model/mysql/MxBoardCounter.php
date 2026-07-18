<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardCounter extends \MxBoard\Model\MxBoardCounter
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_counter',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' =>
        array (
            'engine' => 'InnoDB',
        ),
        'fields' =>
        array (
            'period' => '',
            'value' => 0,
        ),
        'fieldMeta' =>
        array (
            'period' =>
            array (
                'dbtype' => 'varchar',
                'precision' => '20',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'index' => 'unique',
            ),
            'value' =>
            array (
                'dbtype' => 'integer',
                'precision' => '20',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
        ),
        'indexes' =>
        array (
            'period' =>
            array (
                'alias' => 'period',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' =>
                array (
                    'period' =>
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
    );

}
