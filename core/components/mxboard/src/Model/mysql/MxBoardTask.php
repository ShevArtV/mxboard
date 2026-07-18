<?php
namespace MxBoard\Model\mysql;

use xPDO\xPDO;

class MxBoardTask extends \MxBoard\Model\MxBoardTask
{

    public static $metaMap = array (
        'package' => 'MxBoard\\Model',
        'version' => '3.0',
        'table' => 'mxboard_task',
        'extends' => 'xPDO\\Om\\xPDOSimpleObject',
        'tableMeta' => 
        array (
            'engine' => 'InnoDB',
        ),
        'fields' => 
        array (
            'project_id' => 0,
            'parent_id' => 0,
            'type_id' => 0,
            'column_id' => 0,
            'title' => '',
            'tor' => NULL,
            'author_id' => 0,
            'assignee_id' => 0,
            'priority' => 0,
            'position' => 0,
            'deadlineon' => 0,
            'deadline_disputed' => 0,
            'deadline_proposed' => 0,
            'fields' => NULL,
            'meta' => NULL,
            'ai_verdict' => NULL,
            'createdon' => 0,
            'updatedon' => 0,
            'startedon' => 0,
            'closedon' => 0,
        ),
        'fieldMeta' => 
        array (
            'project_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'parent_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'type_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'column_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'title' => 
            array (
                'dbtype' => 'varchar',
                'precision' => '255',
                'phptype' => 'string',
                'null' => false,
                'default' => '',
            ),
            'tor' => 
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'string',
                'null' => true,
            ),
            'author_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'assignee_id' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'priority' => 
            array (
                'dbtype' => 'integer',
                'precision' => '11',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
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
            'deadlineon' => 
            array (
                'dbtype' => 'integer',
                'precision' => '20',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
                'index' => 'index',
            ),
            'deadline_disputed' => 
            array (
                'dbtype' => 'tinyint',
                'precision' => '1',
                'attributes' => 'unsigned',
                'phptype' => 'boolean',
                'null' => false,
                'default' => 0,
            ),
            'deadline_proposed' => 
            array (
                'dbtype' => 'integer',
                'precision' => '20',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'fields' => 
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'json',
                'null' => true,
            ),
            'meta' =>
            array (
                'dbtype' => 'mediumtext',
                'phptype' => 'json',
                'null' => true,
            ),
            'ai_verdict' =>
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
                'index' => 'index',
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
            'startedon' => 
            array (
                'dbtype' => 'integer',
                'precision' => '20',
                'attributes' => 'unsigned',
                'phptype' => 'integer',
                'null' => false,
                'default' => 0,
            ),
            'closedon' => 
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
            'project_id' => 
            array (
                'alias' => 'project_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'project_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'parent_id' => 
            array (
                'alias' => 'parent_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'parent_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'type_id' => 
            array (
                'alias' => 'type_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'type_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'column_id' => 
            array (
                'alias' => 'column_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'column_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'author_id' => 
            array (
                'alias' => 'author_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'author_id' => 
                    array (
                        'length' => '',
                        'collation' => 'A',
                        'null' => false,
                    ),
                ),
            ),
            'assignee_id' => 
            array (
                'alias' => 'assignee_id',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'assignee_id' => 
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
            'deadlineon' => 
            array (
                'alias' => 'deadlineon',
                'primary' => false,
                'unique' => false,
                'type' => 'BTREE',
                'columns' => 
                array (
                    'deadlineon' => 
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
        'composites' => 
        array (
            'Subtasks' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTask',
                'local' => 'id',
                'foreign' => 'parent_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
            'Comments' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardComment',
                'local' => 'id',
                'foreign' => 'task_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
            'Logs' =>
            array (
                'class' => 'MxBoard\\Model\\MxBoardLog',
                'local' => 'id',
                'foreign' => 'task_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
            'Attachments' =>
            array (
                'class' => 'MxBoard\\Model\\MxBoardAttachment',
                'local' => 'id',
                'foreign' => 'task_id',
                'cardinality' => 'many',
                'owner' => 'local',
            ),
        ),
        'aggregates' =>
        array (
            'Project' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardProject',
                'local' => 'project_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
            'Column' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardColumn',
                'local' => 'column_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
            'Type' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTaskType',
                'local' => 'type_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
            'Parent' => 
            array (
                'class' => 'MxBoard\\Model\\MxBoardTask',
                'local' => 'parent_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
            'Author' => 
            array (
                'class' => 'MODX\\Revolution\\modUser',
                'local' => 'author_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
            'Assignee' => 
            array (
                'class' => 'MODX\\Revolution\\modUser',
                'local' => 'assignee_id',
                'foreign' => 'id',
                'cardinality' => 'one',
                'owner' => 'foreign',
            ),
        ),
    );

}
