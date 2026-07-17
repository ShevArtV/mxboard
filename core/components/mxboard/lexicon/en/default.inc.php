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
$_lang['mxboard_err_comment_not_found'] = 'Comment not found.';
$_lang['mxboard_err_comment_author_only'] = 'Only the comment author can edit or delete it.';
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

// v2 (3a): structure CRUD from the manager.
$_lang['mxboard_err_department_not_empty'] = 'Cannot remove the department: it still has projects or task types.';
$_lang['mxboard_err_type_has_tasks'] = 'Cannot remove the type: there are tasks of this type.';
$_lang['mxboard_err_field_not_found'] = 'Type field not found.';
$_lang['mxboard_err_field_exists'] = 'A field with this key already exists on the type.';
$_lang['mxboard_err_field_last'] = 'Cannot remove the last field of the type — the type would become unusable.';
$_lang['mxboard_err_project_has_tasks'] = 'Cannot remove the project: it still has tasks.';
$_lang['mxboard_err_column_exists'] = 'A column with this key already exists in the project.';
$_lang['mxboard_err_column_protected'] = 'Cannot remove the initial or final column — move the flag to another column first.';
$_lang['mxboard_err_column_has_tasks'] = 'Cannot remove the column: it still has tasks.';
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

// v2 (3b): board and task-page UI (loaded into window.MODx.lang by the CMP controller).
$_lang['mxboard_ui_board'] = 'Board';
$_lang['mxboard_ui_tokens'] = 'Agent tokens';
$_lang['mxboard_ui_structure'] = 'Structure';
$_lang['mxboard_ui_refresh'] = 'Refresh';
$_lang['mxboard_ui_cancel'] = 'Cancel';
$_lang['mxboard_ui_create'] = 'Create';
$_lang['mxboard_ui_save'] = 'Save';
$_lang['mxboard_ui_delete'] = 'Delete';
$_lang['mxboard_ui_edit'] = 'Edit';
$_lang['mxboard_ui_send'] = 'Send';
$_lang['mxboard_ui_empty'] = 'Empty';
$_lang['mxboard_ui_loading'] = 'Loading…';

$_lang['mxboard_ui_department'] = 'Department';
$_lang['mxboard_ui_project'] = 'Project';
$_lang['mxboard_ui_filter_all'] = 'All';
$_lang['mxboard_ui_filter_author'] = 'I am author';
$_lang['mxboard_ui_filter_assignee'] = 'I am assignee';
$_lang['mxboard_ui_filter_all_priorities'] = 'All priorities';
$_lang['mxboard_ui_new_task'] = 'New task';
$_lang['mxboard_ui_no_projects'] = 'No projects in this department. Create one on the “Structure” tab.';

$_lang['mxboard_ui_subtask'] = 'Subtask';
$_lang['mxboard_ui_deadline_disputed_hint'] = 'Deadline disputed';

$_lang['mxboard_ui_new_subtask'] = 'New subtask';
$_lang['mxboard_ui_subtask_for'] = 'Subtask of';
$_lang['mxboard_ui_task_type'] = 'Task type';
$_lang['mxboard_ui_select_type'] = 'Choose a type';
$_lang['mxboard_ui_no_types'] = 'The department has no task types — create them on the “Structure” tab.';
$_lang['mxboard_ui_title'] = 'Title';
$_lang['mxboard_ui_deadline'] = 'Deadline';
$_lang['mxboard_ui_priority'] = 'Priority';
$_lang['mxboard_ui_assignee'] = 'Assignee';
$_lang['mxboard_ui_assignee_placeholder'] = 'From the project department';
$_lang['mxboard_ui_tor'] = 'Statement (ToR, markdown)';
$_lang['mxboard_ui_loading_fields'] = 'Loading type fields…';

$_lang['mxboard_ui_to_board'] = 'To board';
$_lang['mxboard_ui_cancel_edit'] = 'Cancel edit';
$_lang['mxboard_ui_parent'] = 'Parent';
$_lang['mxboard_ui_author_label'] = 'Author';
$_lang['mxboard_ui_assignee_label'] = 'Assignee';
$_lang['mxboard_ui_deadline_label'] = 'Deadline';
$_lang['mxboard_ui_overdue'] = 'overdue';
$_lang['mxboard_ui_disputed_to'] = 'disputed';
$_lang['mxboard_ui_accept'] = 'Accept';
$_lang['mxboard_ui_reject'] = 'Reject';
$_lang['mxboard_ui_dispute'] = 'Dispute';
$_lang['mxboard_ui_proposed_date'] = 'Proposed date';
$_lang['mxboard_ui_reason'] = 'Reason';
$_lang['mxboard_ui_reason_placeholder'] = 'Why a reschedule is needed';
$_lang['mxboard_ui_stage'] = 'Stage';
$_lang['mxboard_ui_tor_section'] = 'Statement';
$_lang['mxboard_ui_tor_empty'] = 'Statement is empty';
$_lang['mxboard_ui_type_fields'] = 'Type fields';
$_lang['mxboard_ui_subtasks'] = 'Subtasks';
$_lang['mxboard_ui_no_subtasks'] = 'No subtasks';
$_lang['mxboard_ui_comments'] = 'Comments';
$_lang['mxboard_ui_comment_placeholder'] = 'Comment…';
$_lang['mxboard_ui_comment_edited'] = 'edited';
$_lang['mxboard_ui_no_comments'] = 'No comments';
$_lang['mxboard_ui_log'] = 'Log';
$_lang['mxboard_ui_no_log'] = 'No records';

// Log actions (human-readable).
$_lang['mxboard_act_create'] = 'created';
$_lang['mxboard_act_move'] = 'moved';
$_lang['mxboard_act_close'] = 'closed';
$_lang['mxboard_act_comment'] = 'comment';
$_lang['mxboard_act_update'] = 'updated';
$_lang['mxboard_act_subtask_add'] = 'subtask added';
$_lang['mxboard_act_deadline_dispute'] = 'deadline disputed';
$_lang['mxboard_act_deadline_accepted'] = 'new deadline accepted';
$_lang['mxboard_act_deadline_rejected'] = 'dispute rejected';

// Toasts and confirmations.
$_lang['mxboard_msg_refs_load'] = 'Reference data not loaded';
$_lang['mxboard_msg_board_load'] = 'Board not loaded';
$_lang['mxboard_msg_move_rejected'] = 'Move rejected';
$_lang['mxboard_msg_task_created'] = 'Task created';
$_lang['mxboard_msg_task_not_created'] = 'Task not created';
$_lang['mxboard_msg_schema_load'] = 'Type schema not loaded';
$_lang['mxboard_msg_warn_no_type'] = 'No task type selected';
$_lang['mxboard_msg_warn_no_title'] = 'Title is required';
$_lang['mxboard_msg_warn_no_deadline'] = 'Deadline is required';
$_lang['mxboard_msg_warn_no_assignee'] = 'No assignee selected';
$_lang['mxboard_msg_task_load'] = 'Task not loaded';
$_lang['mxboard_msg_rejected'] = 'Denied';
$_lang['mxboard_msg_stage_changed'] = 'Stage changed';
$_lang['mxboard_msg_comment_added'] = 'Comment added';
$_lang['mxboard_msg_comment_updated'] = 'Comment updated';
$_lang['mxboard_msg_comment_deleted'] = 'Comment deleted';
$_lang['mxboard_msg_confirm_delete_comment'] = 'Delete this comment?';
$_lang['mxboard_msg_saved'] = 'Saved';
$_lang['mxboard_msg_deadline_disputed'] = 'Deadline disputed';
$_lang['mxboard_msg_deadline_accepted'] = 'New deadline accepted';
$_lang['mxboard_msg_deadline_rejected'] = 'Dispute rejected';
$_lang['mxboard_msg_task_deleted'] = 'Task deleted';
$_lang['mxboard_msg_warn_proposed_date'] = 'Specify the proposed date';
$_lang['mxboard_msg_confirm_delete_task'] = 'Delete the task? Subtasks will be detached, not deleted.';

// Agent tokens (tab).
$_lang['mxboard_ui_new_token'] = 'New token';
$_lang['mxboard_ui_new_token_agent'] = 'New agent token';
$_lang['mxboard_ui_token_created_banner'] = 'Token created.';
$_lang['mxboard_ui_token_created_hint'] = 'Save it now — it is shown once and will not be available again: only a hash is stored in the database.';
$_lang['mxboard_ui_copy'] = 'Copy';
$_lang['mxboard_ui_col_name'] = 'Name';
$_lang['mxboard_ui_col_user'] = 'User';
$_lang['mxboard_ui_col_status'] = 'Status';
$_lang['mxboard_ui_col_created'] = 'Created';
$_lang['mxboard_ui_col_used'] = 'Used';
$_lang['mxboard_ui_status_active'] = 'active';
$_lang['mxboard_ui_status_revoked'] = 'revoked';
$_lang['mxboard_ui_no_tokens'] = 'No tokens';
$_lang['mxboard_ui_token_name'] = 'Name';
$_lang['mxboard_ui_token_user'] = 'MODX user ID';
$_lang['mxboard_ui_token_user_hint'] = 'The agent has the permissions of its MODX user. Your ID is prefilled by default.';
$_lang['mxboard_ui_token_name_placeholder'] = 'e.g. jarvis-worker';
$_lang['mxboard_msg_tokens_load'] = 'Tokens not loaded';
$_lang['mxboard_msg_token_name_required'] = 'Token name is required';
$_lang['mxboard_msg_token_created'] = 'Token created';
$_lang['mxboard_msg_token_not_created'] = 'Token not created';
$_lang['mxboard_msg_token_no_value'] = 'The server did not return the token value — check the Token\\Create processor.';
$_lang['mxboard_msg_token_copied'] = 'Token copied';
$_lang['mxboard_msg_token_copy_manual'] = 'Copy manually';
$_lang['mxboard_msg_token_revoke_fail'] = 'Failed to revoke';
$_lang['mxboard_msg_token_revoked'] = 'Token revoked';
$_lang['mxboard_msg_confirm_revoke'] = 'Revoke token “[[+name]]”? The agent using it will stop working.';

// v2 (3c): Structure screen (managers only).
$_lang['mxboard_ui_struct_departments'] = 'Departments';
$_lang['mxboard_ui_struct_types'] = 'Task types';
$_lang['mxboard_ui_struct_projects'] = 'Projects';
$_lang['mxboard_ui_struct_columns'] = 'Columns/stages';

$_lang['mxboard_ui_struct_name'] = 'Name';
$_lang['mxboard_ui_struct_key'] = 'Key';
$_lang['mxboard_ui_struct_description'] = 'Description';
$_lang['mxboard_ui_struct_active'] = 'Active';
$_lang['mxboard_ui_struct_position'] = 'Position';
$_lang['mxboard_ui_struct_actions'] = 'Actions';
$_lang['mxboard_ui_struct_add'] = 'Add';
$_lang['mxboard_ui_struct_edit'] = 'Edit';
$_lang['mxboard_ui_struct_saved'] = 'Saved';
$_lang['mxboard_ui_struct_removed'] = 'Removed';
$_lang['mxboard_ui_struct_created'] = 'Created';
$_lang['mxboard_ui_struct_empty'] = 'Empty';

// Departments.
$_lang['mxboard_ui_struct_register_dept'] = 'Register department';
$_lang['mxboard_ui_struct_usergroup'] = 'MODX user group';
$_lang['mxboard_ui_struct_usergroup_hint'] = 'A department is a MODX group. Membership and roles come from MODX; this is only a marker.';
$_lang['mxboard_ui_struct_already_dept'] = 'already a department';
$_lang['mxboard_ui_struct_confirm_remove_dept'] = 'Unmark “[[+name]]” as a department? Only if it has no projects or types.';

// Task types.
$_lang['mxboard_ui_struct_new_type'] = 'New task type';
$_lang['mxboard_ui_struct_type_fields'] = 'Type fields';
$_lang['mxboard_ui_struct_add_field'] = 'Add field';
$_lang['mxboard_ui_struct_field_key'] = 'Field key';
$_lang['mxboard_ui_struct_field_label'] = 'Caption';
$_lang['mxboard_ui_struct_field_type'] = 'Field type';
$_lang['mxboard_ui_struct_field_required'] = 'Required';
$_lang['mxboard_ui_struct_field_min'] = 'A type must keep at least one field.';
$_lang['mxboard_ui_struct_confirm_remove_type'] = 'Delete type “[[+name]]”? Only if it has no tasks.';
$_lang['mxboard_ui_struct_confirm_remove_field'] = 'Delete field “[[+name]]”?';
$_lang['mxboard_ui_struct_pick_department'] = 'Choose a department';

// Projects.
$_lang['mxboard_ui_struct_new_project'] = 'New project';
$_lang['mxboard_ui_struct_confirm_remove_project'] = 'Delete project “[[+name]]”? Only if it has no tasks.';
$_lang['mxboard_ui_struct_columns_from_template'] = 'Columns will be created from the template (project_id=0).';

// Columns/stages.
$_lang['mxboard_ui_struct_pick_project'] = 'Choose a project';
$_lang['mxboard_ui_struct_template'] = 'New-project template';
$_lang['mxboard_ui_struct_new_column'] = 'New column';
$_lang['mxboard_ui_struct_move_roles'] = 'Transition roles (CSV)';
$_lang['mxboard_ui_struct_move_roles_hint'] = 'author, assignee, any or group:Name — who may move a card INTO this column.';
$_lang['mxboard_ui_struct_stage_key'] = 'Stage key';
$_lang['mxboard_ui_struct_is_initial'] = 'Initial';
$_lang['mxboard_ui_struct_is_final'] = 'Final';
$_lang['mxboard_ui_struct_color'] = 'Color';
$_lang['mxboard_ui_struct_flag_transfer'] = 'The initial/final flag is moved from the previous column — there is always exactly one of each.';
$_lang['mxboard_ui_struct_confirm_remove_column'] = 'Delete column “[[+name]]”? Not allowed for initial/final or a non-empty column.';

// v2 (3d): "Agent token" widget on the user profile page (sudo only).
$_lang['mxboard_ui_profile_section'] = 'mxBoard agent token';
$_lang['mxboard_ui_profile_hint'] = 'One token per user for agent access to REST/MCP. The agent has this user\'s permissions.';
$_lang['mxboard_ui_profile_generate'] = 'Generate';
$_lang['mxboard_ui_profile_regenerate'] = 'Reissue';
$_lang['mxboard_ui_profile_none'] = 'No token issued';
$_lang['mxboard_ui_profile_created'] = 'Issued';
$_lang['mxboard_ui_profile_confirm_regen'] = 'Reissue the token? The previous one will stop working.';
$_lang['mxboard_ui_profile_save_first'] = 'Save the user first, then issue a token.';
$_lang['mxboard_ui_profile_copied'] = 'Token copied';
