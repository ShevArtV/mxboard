<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardField extends \MxBoard\Model\MxBoardField
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_field',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'task_type_id' => 0,
            'key' => '',
            'label' => '',
            'type' => 'text',
            'required' => 0,
            'position' => 0,
            'options' => NULL,
            'createdon' => 0,
        ),
        'fieldMeta' => 
        array (
            'task_type_id' => 
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
            'label' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '191',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'type' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '20',
                'phptype' => 'string',
                'null' => false,
                'default' => 'text',
            ),
            'required' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 0,
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
            'options' => 
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'json',
                'null' => true,
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
            'task_type_id' => 
            array (
                'alias' => 'task_type_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'task_type_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'type_field_key' => 
            array (
                'alias' => 'type_field_key',
                'primary' => false,
                'unique' => true,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'task_type_id' => 
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
        'aggregates' => 
        array (
            'Type' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTaskType',
                'local' => 'task_type_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
