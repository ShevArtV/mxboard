<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardColumn extends \MxBoard\Model\MxBoardColumn
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_column',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'board_id' => 0,
            'key' => '',
            'name' => '',
            'rank' => 0,
            'move_roles' => '',
            'is_initial' => 0,
            'is_ready' => 0,
            'is_final' => 0,
            'createdon' => 0,
        ),
        'fieldMeta' => 
        array (
            'board_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'key' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'index' => 'index',
            ),
            'name' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '191',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'rank' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'move_roles' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'is_initial' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 0,
            ),
            'is_ready' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 0,
            ),
            'is_final' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
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
            'board_id' => 
            array (
                'alias' => 'board_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'board_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'board_column_key' => 
            array (
                'alias' => 'board_column_key',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'board_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                    'key' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'rank' => 
            array (
                'alias' => 'rank',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'rank' => 
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
            'Tasks' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTask',
                'local' => 'id',
                'foreign' => 'column_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
        ),
        'aggregates' => 
        array (
            'Board' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardBoard',
                'local' => 'board_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
