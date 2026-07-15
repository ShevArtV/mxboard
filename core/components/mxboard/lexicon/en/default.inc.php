<?php

$_lang['mxboard'] = 'mxBoard';
$_lang['mxboard_menu_desc'] = 'Kanban board for AI agents';
$_lang['mxboard_error'] = 'mxBoard: error';
$_lang['mxboard_vuetools_required'] = 'VueTools package is required. Install it via Package Management.';

$_lang['mxboard_board'] = 'Board';
$_lang['mxboard_column'] = 'Column';
$_lang['mxboard_task'] = 'Task';
$_lang['mxboard_task_title'] = 'Title';
$_lang['mxboard_task_tor'] = 'Terms of reference';
$_lang['mxboard_task_author'] = 'Author';
$_lang['mxboard_task_assignee'] = 'Assignee';
$_lang['mxboard_task_free'] = 'Unassigned';

$_lang['mxboard_err_move_denied'] = 'You are not allowed to move the task to this column.';
$_lang['mxboard_err_close_author_only'] = 'Only the task author can close it.';
$_lang['mxboard_err_self_close'] = 'You cannot close a task where you are both the author and the assignee.';
$_lang['mxboard_err_already_taken'] = 'The task has already been taken by another assignee.';
$_lang['mxboard_err_not_ready'] = 'A task can only be taken from the "Ready" column.';
$_lang['mxboard_err_wip_limit'] = 'Work-in-progress limit reached.';
$_lang['mxboard_err_token_invalid'] = 'Invalid token.';
$_lang['mxboard_err_mcp_disabled'] = 'The MCP endpoint is disabled by the mxboard.mcp_enabled system setting.';
$_lang['mxboard_err_task_id_required'] = 'task_id is required.';
$_lang['mxboard_err_column_required'] = 'Column key is required.';
$_lang['mxboard_err_board_not_found'] = 'Board not found.';
$_lang['mxboard_err_column_not_found'] = 'Column not found.';
$_lang['mxboard_err_task_not_found'] = 'Task not found.';
$_lang['mxboard_err_title_required'] = 'Task title is required.';
$_lang['mxboard_err_no_initial_column'] = 'The board has no initial column.';
$_lang['mxboard_err_no_next_column'] = 'No next column to move the task to.';
$_lang['mxboard_err_comment_empty'] = 'Comment is empty.';
$_lang['mxboard_err_save'] = 'Failed to save.';
$_lang['mxboard_err_unauthenticated'] = 'Authentication required.';
$_lang['mxboard_err_edit_denied'] = 'Only the task author can edit it.';
$_lang['mxboard_err_remove_denied'] = 'Only the task author can remove it.';
$_lang['mxboard_err_remove_failed'] = 'Failed to remove.';
$_lang['mxboard_err_user_not_found'] = 'User not found.';

// v2: hierarchy, types, deadlines, subtasks.
$_lang['mxboard_err_project_not_found'] = 'Project not found.';
$_lang['mxboard_err_parent_not_found'] = 'Parent task not found.';
$_lang['mxboard_err_parent_other_project'] = 'A subtask must belong to the same project as its parent.';
$_lang['mxboard_err_subtask_denied'] = 'Only the author or assignee of the parent task can create a subtask.';
$_lang['mxboard_err_title_too_long'] = 'Title exceeds 250 characters.';
$_lang['mxboard_err_deadline_required'] = 'A valid deadline is required.';
$_lang['mxboard_err_type_required'] = 'Task type is required.';
$_lang['mxboard_err_type_not_found'] = 'Task type not found in this department.';
$_lang['mxboard_err_type_no_fields'] = 'The task type has no fields — it is not a working type.';
$_lang['mxboard_err_field_required'] = 'Not all required type fields are filled in.';
$_lang['mxboard_err_open_subtasks'] = 'Cannot close the task: it has unfinished subtasks.';
$_lang['mxboard_err_assignee_required'] = 'Assignee is required.';
$_lang['mxboard_err_assignee_not_found'] = 'Assignee not found or blocked.';
$_lang['mxboard_err_assignee_not_in_department'] = 'The assignee must be a member of the project department.';
$_lang['mxboard_err_view_denied'] = 'You have no access to this task.';
$_lang['mxboard_err_dispute_assignee_only'] = 'Only the assignee can dispute the deadline.';
$_lang['mxboard_err_no_dispute'] = 'There is no active deadline dispute on this task.';
$_lang['mxboard_err_author_only'] = 'This action is available only to the task author or a manager.';

// v2: structure (MCP/REST) and routing.
$_lang['mxboard_err_group_required'] = 'User group is required.';
$_lang['mxboard_err_group_not_found'] = 'User group not found.';
$_lang['mxboard_err_structure_denied'] = 'Structure operations are available only to a department manager.';
$_lang['mxboard_err_department_required'] = 'Department is required.';
$_lang['mxboard_err_department_not_found'] = 'Department not found.';
$_lang['mxboard_err_type_key_name_required'] = 'Task type requires a key and a name.';
$_lang['mxboard_err_type_exists'] = 'A task type with this key already exists in the department.';
$_lang['mxboard_err_field_invalid'] = 'Invalid type field (key and label are required).';
$_lang['mxboard_err_field_duplicate'] = 'Duplicate field key.';
$_lang['mxboard_err_field_type_invalid'] = 'Invalid field type.';
$_lang['mxboard_err_project_key_name_required'] = 'Project requires a key and a name.';
$_lang['mxboard_err_project_exists'] = 'A project with this key already exists in the department.';
$_lang['mxboard_err_column_invalid'] = 'Invalid column (key and name are required).';
$_lang['mxboard_err_column_duplicate'] = 'Duplicate column key.';
$_lang['mxboard_err_column_invariant'] = 'Exactly one initial and exactly one final column are required.';
$_lang['mxboard_err_no_template_columns'] = 'No column template (project_id=0).';
$_lang['mxboard_err_route_not_found'] = 'Route not found.';
$_lang['mxboard_err_api_disabled'] = 'REST API is disabled by the mxboard.api_enabled system setting.';

$_lang['mxboard_department'] = 'Department';
$_lang['mxboard_project'] = 'Project';
$_lang['mxboard_task_type'] = 'Task type';
$_lang['mxboard_task_field'] = 'Field';
$_lang['mxboard_task_deadline'] = 'Deadline';
$_lang['mxboard_task_subtasks'] = 'Subtasks';
$_lang['mxboard_deadline_disputed'] = 'Deadline disputed';

$_lang['mxboard_token'] = 'Token';
$_lang['mxboard_token_created'] = 'Token created. Copy it now — it will not be shown again.';
