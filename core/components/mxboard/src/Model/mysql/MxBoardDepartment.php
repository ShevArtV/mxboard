<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardDepartment extends \MxBoard\Model\MxBoardDepartment
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_department',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'usergroup_id' => 0,
            'name' => '',
            'active' => 1,
            'position' => 0,
            'createdon' => 0,
        ),
        'fieldMeta' => 
        array (
            'usergroup_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
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
            'position' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
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
        ),
        'indexes' => 
        array (
            'usergroup_id' => 
            array (
                'alias' => 'usergroup_id',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'usergroup_id' => 
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
            'position' => 
            array (
                'alias' => 'position',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'position' => 
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
            'Projects' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardProject',
                'local' => 'id',
                'foreign' => 'department_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
            'Types' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTaskType',
                'local' => 'id',
                'foreign' => 'department_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
        ),
        'aggregates' => 
        array (
            'Group' => 
            array (
                'class' => 'MODX\\Revolution\\modUserGroup',
                'local' => 'usergroup_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
