<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardBoard extends \MxBoard\Model\MxBoardBoard
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_board',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'key' => '',
            'name' => '',
            'description' => NULL,
            'active' => 1,
            'createdon' => 0,
            'updatedon' => 0,
        ),
        'fieldMeta' => 
        array (
            'key' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'index' => 'unique',
            ),
            'name' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '191',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'description' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
            ),
            'active' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 1,
                'index' => 'index',
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
            'updatedon' => 
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
            'key' => 
            array (
                'alias' => 'key',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'key' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'active' => 
            array (
                'alias' => 'active',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'active' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
        ),
        'composites' => 
        array (
            'Columns' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardColumn',
                'local' => 'id',
                'foreign' => 'board_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
            'Tasks' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTask',
                'local' => 'id',
                'foreign' => 'board_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
        ),
    );

}
