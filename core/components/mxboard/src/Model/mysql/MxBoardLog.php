<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardLog extends \MxBoard\Model\MxBoardLog
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_log',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'task_id' => 0,
            'user_id' => 0,
            'action' => '',
            'from_column' => '',
            'to_column' => '',
            'note' => NULL,
            'channel' => 'mgr',
            'createdon' => 0,
        ),
        'fieldMeta' => 
        array (
            'task_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'user_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'action' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '32',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
                'index' => 'index',
            ),
            'from_column' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'to_column' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '100',
                'phptype' => 'string',
                'null' => true,
                'default' => '',
            ),
            'note' => 
            array (
                'dbtype' => 'text',
                'phptype' => 'string',
                'null' => true,
            ),
            'channel' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '16',
                'phptype' => 'string',
                'null' => false,
                'default' => 'mgr',
            ),
            'createdon' => 
            array (
                'dbtype' => 'integer',
                'precision' => '20',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
        ),
        'indexes' => 
        array (
            'task_id' => 
            array (
                'alias' => 'task_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'task_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'user_id' => 
            array (
                'alias' => 'user_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'user_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'action' => 
            array (
                'alias' => 'action',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'action' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'createdon' => 
            array (
                'alias' => 'createdon',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'createdon' => 
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
            'Task' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTask',
                'local' => 'task_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
            'User' => 
            array (
                'class' => 'MODX\\Revolution\\modUser',
                'local' => 'user_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
