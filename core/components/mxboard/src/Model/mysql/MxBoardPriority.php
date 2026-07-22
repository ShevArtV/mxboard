<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardPriority extends \MxBoard\Model\MxBoardPriority
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_priority',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' =>
        array (
            'engine' => 'InnoDB',
        ),
        'fields' =>
        array (
            'name' => '',
            'color' => '#6c757d',
            'value' => 0,
            'createdon' => 0,
        ),
        'fieldMeta' =>
        array (
            'name' =>
            array (
                'dbtype' => 'varchar',
                'precision' => '191',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'color' =>
            array (
                'dbtype' => 'varchar',
                'precision' => '7',
                'phptype' => 'string',
                'null' => false,
                'default' => '#6c757d',
            ),
            'value' =>
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'createdon' =>
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
            // Уникальность на уровне БД — инварианты 3 и 4 из ToR (не только PHP).
            'value' =>
            array (
                'alias' => 'value',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' =>
                array (
                    'value' =>
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'name' =>
            array (
                'alias' => 'name',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' =>
                array (
                    'name' =>
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
