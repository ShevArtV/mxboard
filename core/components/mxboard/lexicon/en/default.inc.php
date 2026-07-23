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
$_lang['mxboard_err_parent_final'] = 'Cannot create a subtask for a task in a final stage.';
$_lang['mxboard_err_task_closed'] = 'The task is closed: changes are not allowed. Only the stage can be changed — move the card out of the final stage to edit it.';
$_lang['mxboard_err_subtask_denied'] = 'Only the author or assignee of the parent task can create a subtask.';
$_lang['mxboard_err_title_too_long'] = 'Title exceeds 250 characters.';
$_lang['mxboard_err_deadline_required'] = 'A valid deadline is required.';
$_lang['mxboard_err_type_required'] = 'Task type is required.';
$_lang['mxboard_err_type_not_found'] = 'Task type not found in this department.';
$_lang['mxboard_err_type_no_fields'] = 'The task type has no fields — it is not a working type.';
$_lang['mxboard_err_field_required'] = 'Not all required type fields are filled in.';
$_lang['mxboard_err_field_unknown'] = 'Unknown task type field was provided.';
$_lang['mxboard_err_open_subtasks'] = 'Cannot close the task: it has unfinished subtasks.';
$_lang['mxboard_err_assignee_required'] = 'Assignee is required.';
$_lang['mxboard_err_assignee_not_found'] = 'Assignee not found or blocked.';
$_lang['mxboard_err_assignee_not_in_department'] = 'The assignee must be a member of the project department.';
$_lang['mxboard_err_view_denied'] = 'You have no access to this task.';
$_lang['mxboard_err_dispute_assignee_only'] = 'Only the assignee can dispute this.';
$_lang['mxboard_err_no_dispute'] = 'There is no active dispute on this task.';
$_lang['mxboard_err_plan_not_set'] = 'No planned time is set — there is nothing to dispute.';
$_lang['mxboard_err_plan_required'] = 'Specify the proposed estimate in hours.';
$_lang['mxboard_err_author_only'] = 'This action is available only to the task author or a manager.';
$_lang['mxboard_err_ai_incomplete'] = 'AI check: the task statement lacks the data needed to work on it.';

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
$_lang['mxboard_err_column_invariant'] = 'Exactly one initial and one final column are required; at most one start column.';
$_lang['mxboard_err_start_is_initial'] = 'The initial stage cannot be the start stage: tracking would begin the moment the task is created.';
$_lang['mxboard_err_no_template_columns'] = 'No column template (project_id=0).';
$_lang['mxboard_err_copy_has_tasks'] = 'Cannot copy columns: the project already has tasks.';
$_lang['mxboard_err_copy_source_invalid'] = 'Invalid columns source.';
$_lang['mxboard_err_copy_source_empty'] = 'The source has no columns to copy.';
$_lang['mxboard_err_reorder_mismatch'] = 'Reorder list does not match the project columns.';
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
$_lang['mxboard_err_priority_not_found'] = 'Priority not found.';
$_lang['mxboard_err_priority_name_required'] = 'Priority name is required.';
$_lang['mxboard_err_priority_value_invalid'] = 'Priority value must be a non-negative integer.';
$_lang['mxboard_err_priority_value_exists'] = 'A priority with this numeric value already exists.';
$_lang['mxboard_err_priority_name_exists'] = 'A priority with this name already exists.';
$_lang['mxboard_err_priority_last'] = 'Cannot remove the last priority — the dictionary must not be empty.';
$_lang['mxboard_err_priority_unknown'] = 'No such priority in the dictionary.';
$_lang['mxboard_err_api_disabled'] = 'REST API is disabled by the mxboard.api_enabled system setting.';

// Subtask A: task and chat message attachments.
$_lang['mxboard_err_upload_no_file'] = 'No file provided.';
$_lang['mxboard_err_upload_failed'] = 'Failed to upload the file.';
$_lang['mxboard_err_upload_ext'] = 'File extension is not allowed.';
$_lang['mxboard_err_upload_size'] = 'File exceeds the allowed size.';
$_lang['mxboard_err_upload_too_many'] = 'Too many files at once.';
$_lang['mxboard_err_source_unavailable'] = 'Attachment media source is unavailable — check the mxboard.media_source setting.';
$_lang['mxboard_err_attachment_not_found'] = 'Attachment not found.';
$_lang['mxboard_err_attachment_denied'] = 'Only the file author, task author, or a manager can remove an attachment.';

$_lang['mxboard_department'] = 'Department';
$_lang['mxboard_project'] = 'Project';
$_lang['mxboard_task_type'] = 'Task type';
$_lang['mxboard_task_field'] = 'Field';
$_lang['mxboard_task_deadline'] = 'Deadline';
$_lang['mxboard_task_plan'] = 'Planned time';
$_lang['mxboard_task_subtasks'] = 'Subtasks';
$_lang['mxboard_deadline_disputed'] = 'Deadline disputed';
$_lang['mxboard_plan_disputed'] = 'Planned time disputed';

// MODX system settings.
$_lang['area_mxboard_main'] = 'Main';
$_lang['area_mxboard_api'] = 'API';
$_lang['area_mxboard_rights'] = 'Permissions';
$_lang['area_mxboard_ai'] = 'AI review';

$_lang['setting_mxboard.task_num_format'] = 'Task number format';
$_lang['setting_mxboard.task_num_format_desc'] = 'Human-readable task number template. Supports {Y}, {y}, {m}, {d}, {num}; the counter reset period is derived from the smallest date placeholder.';
$_lang['setting_mxboard.default_project'] = 'Default project';
$_lang['setting_mxboard.default_project_desc'] = 'Project key used when a client does not specify a project explicitly.';
$_lang['setting_mxboard.default_board'] = 'Default board';
$_lang['setting_mxboard.default_board_desc'] = 'Legacy setting from early mxBoard versions. Kept for correct display on stands where it already exists in the database; current versions use mxboard.default_project.';
$_lang['setting_mxboard.api_enabled'] = 'Enable REST API';
$_lang['setting_mxboard.api_enabled_desc'] = 'Allows the mxBoard REST API for agents and external clients.';
$_lang['setting_mxboard.mcp_enabled'] = 'Enable MCP endpoint';
$_lang['setting_mxboard.mcp_enabled_desc'] = 'Allows the mxBoard JSON-RPC MCP endpoint for AI agents.';
$_lang['setting_mxboard.allow_self_close'] = 'Allow self-closing tasks';
$_lang['setting_mxboard.allow_self_close_desc'] = 'Allows closing a card where the author and assignee are the same user.';
$_lang['setting_mxboard.wip_limit'] = 'Assignee WIP limit';
$_lang['setting_mxboard.wip_limit_desc'] = 'How many tasks one assignee may keep in progress at the same time. 0 means no limit.';
$_lang['setting_mxboard.group_admin_authority'] = 'Department manager authority threshold';
$_lang['setting_mxboard.group_admin_authority_desc'] = 'Authority threshold at which a department group member is treated as its manager. In MODX, lower authority means more rights; 1 means only the top-level role, 0 disables it.';
$_lang['setting_mxboard.kiosk_usergroups'] = 'Kiosk-mode groups';
$_lang['setting_mxboard.kiosk_usergroups_desc'] = 'CSV list of MODX user group names whose regular non-sudo members are redirected straight to the board when entering the manager. Empty disables the behavior.';
$_lang['setting_mxboard.media_source'] = 'Attachment media source';
$_lang['setting_mxboard.media_source_desc'] = 'MODX media source ID for task attachments, comment attachments, and file fields. 0 means the default source.';
$_lang['setting_mxboard.upload_max_size'] = 'Maximum attachment size';
$_lang['setting_mxboard.upload_max_size_desc'] = 'Maximum size of one attachment in bytes. 0 means mxBoard adds no separate limit; media source and MODX limits still apply.';
$_lang['setting_mxboard.upload_max_files'] = 'Maximum files per batch';
$_lang['setting_mxboard.upload_max_files_desc'] = 'How many files can be attached in one drag-and-drop or file picker batch. 0 means no limit.';
$_lang['setting_mxboard.upload_extensions'] = 'Allowed attachment extensions';
$_lang['setting_mxboard.upload_extensions_desc'] = 'Comma-separated list of allowed extensions. Empty means mxBoard does not restrict extensions, but media source and upload_files still apply.';
$_lang['setting_mxboard.sse_enabled'] = 'Enable SSE notifications';
$_lang['setting_mxboard.sse_enabled_desc'] = 'Enables live board notifications in the manager via Server-Sent Events.';
$_lang['setting_mxboard.sse_lifetime'] = 'SSE connection lifetime';
$_lang['setting_mxboard.sse_lifetime_desc'] = 'Lifetime of one SSE connection in seconds. The client reconnects automatically.';
$_lang['setting_mxboard.sse_poll_interval'] = 'SSE poll interval';
$_lang['setting_mxboard.sse_poll_interval_desc'] = 'Interval for polling new notifications inside an SSE connection, in seconds.';
$_lang['setting_mxboard.ai_base_url'] = 'AI API URL';
$_lang['setting_mxboard.ai_base_url_desc'] = 'Base URL of an OpenAI-compatible endpoint. The client appends /chat/completions.';
$_lang['setting_mxboard.ai_api_key'] = 'AI API key';
$_lang['setting_mxboard.ai_api_key_desc'] = 'Bearer access key for the AI provider. Empty disables AI review.';
$_lang['setting_mxboard.ai_model'] = 'AI model';
$_lang['setting_mxboard.ai_model_desc'] = 'Model name used for AI review of task statements.';
$_lang['setting_mxboard.ai_check_mode'] = 'AI review mode';
$_lang['setting_mxboard.ai_check_mode_desc'] = 'strict blocks incomplete task creation; soft shows a warning and allows creation.';
$_lang['setting_mxboard.ai_check_prompt'] = 'AI review prompt';
$_lang['setting_mxboard.ai_check_prompt_desc'] = 'Global prompt template for task-statement completeness review. A task type can override it with its own ai_prompt.';

$_lang['mxboard_token'] = 'Token';
$_lang['mxboard_token_created'] = 'Token created. Copy it now — it will not be shown again.';

// v2 (3b): board and task-page UI (loaded into window.MODx.lang by the CMP controller).
$_lang['mxboard_ui_board'] = 'Board';
$_lang['mxboard_ui_tokens'] = 'Agent tokens';
$_lang['mxboard_ui_structure'] = 'Structure';
$_lang['mxboard_ui_refresh'] = 'Refresh';
$_lang['mxboard_ui_cancel'] = 'Cancel';
$_lang['mxboard_ui_create'] = 'Create';
$_lang['mxboard_ui_discard'] = 'Discard';
$_lang['mxboard_ui_keep_editing'] = 'Keep editing';
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
$_lang['mxboard_ui_reset_filters'] = 'Reset filters';
$_lang['mxboard_ui_new_task'] = 'New task';
$_lang['mxboard_ui_no_projects'] = 'No projects in this department. Create one on the “Structure” tab.';

$_lang['mxboard_ui_subtask'] = 'Subtask';
$_lang['mxboard_ui_deadline_disputed_hint'] = 'Deadline disputed';

$_lang['mxboard_ui_new_subtask'] = 'New subtask';
$_lang['mxboard_ui_subtask_for'] = 'Subtask of';
$_lang['mxboard_ui_parent_final_no_subtask'] = 'Cannot create subtasks for a final task';
$_lang['mxboard_ui_task_closed'] = 'The task is closed — changes are unavailable';
$_lang['mxboard_ui_task_closed_hint'] = 'The task is in a final stage: edits, comments and attachments are unavailable. Move it out of the final stage to continue working.';
$_lang['mxboard_ui_task_type'] = 'Task type';
$_lang['mxboard_ui_select_type'] = 'Choose a type';
$_lang['mxboard_ui_no_types'] = 'The department has no task types — create them on the “Structure” tab.';
$_lang['mxboard_ui_title'] = 'Title';
$_lang['mxboard_ui_deadline'] = 'Deadline';
$_lang['mxboard_ui_plan'] = 'Plan, h';
$_lang['mxboard_ui_plan_hint'] = 'Optional. The assignee may dispute the estimate.';
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
$_lang['mxboard_ui_plan_label'] = 'Plan';
$_lang['mxboard_ui_fact_label'] = 'Actual';
$_lang['mxboard_ui_hours_short'] = 'h';
$_lang['mxboard_ui_fact_running'] = 'running';
$_lang['mxboard_ui_fact_none'] = 'not started';
$_lang['mxboard_ui_plan_disputed_hint'] = 'Planned time disputed';
$_lang['mxboard_ui_dispute_plan'] = 'Dispute estimate';
$_lang['mxboard_ui_proposed_hours'] = 'Your estimate, h';
$_lang['mxboard_ui_overdue'] = 'overdue';
$_lang['mxboard_ui_disputed_to'] = 'disputed';
$_lang['mxboard_ui_accept'] = 'Accept';
$_lang['mxboard_ui_reject'] = 'Reject';
$_lang['mxboard_ui_dispute'] = 'Dispute';
$_lang['mxboard_ui_resolve'] = 'Resolve';
$_lang['mxboard_ui_resolve_title'] = 'Resolve dispute';
$_lang['mxboard_ui_proposed_date'] = 'Proposed date';
$_lang['mxboard_ui_reason'] = 'Reason';
$_lang['mxboard_ui_reason_placeholder'] = 'Why a reschedule is needed';
$_lang['mxboard_ui_reason_max_hint'] = 'Up to 1000 characters, the rest will be trimmed';
$_lang['mxboard_ui_reason_empty'] = 'No reason provided';
$_lang['mxboard_ui_stage'] = 'Stage';
$_lang['mxboard_ui_tor_section'] = 'Statement';
$_lang['mxboard_ui_tor_empty'] = 'Statement is empty';
$_lang['mxboard_ui_type_fields'] = 'Type fields';
$_lang['mxboard_ui_ai_verdict'] = 'AI completeness check';
$_lang['mxboard_ui_ai_ok'] = 'complete';
$_lang['mxboard_ui_ai_incomplete_short'] = 'incomplete';
$_lang['mxboard_ui_ai_overridden'] = 'created despite';
$_lang['mxboard_ui_ai_incomplete'] = 'AI check: the statement is incomplete — data is missing to start work';
$_lang['mxboard_ui_ai_create_anyway'] = 'Create anyway';
$_lang['mxboard_ui_subtasks'] = 'Subtasks';
$_lang['mxboard_ui_no_subtasks'] = 'No subtasks';
$_lang['mxboard_ui_comments'] = 'Comments';
$_lang['mxboard_ui_comment_placeholder'] = 'Comment…';
$_lang['mxboard_ui_comment_edited'] = 'edited';
$_lang['mxboard_ui_no_comments'] = 'No comments';
$_lang['mxboard_ui_log'] = 'Log';
$_lang['mxboard_ui_no_log'] = 'No records';

// Task card (two-column + chat).
$_lang['mxboard_ui_chat'] = 'Task chat';
$_lang['mxboard_ui_setter'] = 'Reporter';
$_lang['mxboard_ui_created'] = 'Created';
$_lang['mxboard_ui_task_id'] = 'ID';
$_lang['mxboard_ui_attach_soon'] = 'File attachments — coming soon';

// Subtask C: front-end attachments (composer, task files block, file field).
$_lang['mxboard_ui_task_files'] = 'Task files';
$_lang['mxboard_ui_attach_file'] = 'Attach file';
$_lang['mxboard_ui_no_files'] = 'No files';
$_lang['mxboard_ui_download'] = 'Download';
$_lang['mxboard_ui_expand'] = 'Expand';
$_lang['mxboard_ui_collapse'] = 'Collapse';
$_lang['mxboard_ui_clear'] = 'Clear';
$_lang['mxboard_ui_file_replace'] = 'Replace file';
$_lang['mxboard_ui_file_after_save'] = 'You can attach a file after saving the task';
$_lang['mxboard_ui_files_message'] = 'Attached files';
$_lang['mxboard_ui_drop_hint'] = 'Drop files here or click to choose';
$_lang['mxboard_ui_too_many_files'] = 'You can attach at most {max} files at once';

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
$_lang['mxboard_act_plan_dispute'] = 'planned time disputed';
$_lang['mxboard_act_plan_accepted'] = 'new estimate accepted';
$_lang['mxboard_act_plan_rejected'] = 'estimate dispute rejected';
$_lang['mxboard_act_ai_check'] = 'AI check';

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
$_lang['mxboard_msg_file_uploaded'] = 'File uploaded';
$_lang['mxboard_msg_file_deleted'] = 'File deleted';
$_lang['mxboard_msg_confirm_delete_file'] = 'Delete this file?';
$_lang['mxboard_msg_upload_partial'] = 'Some files were not uploaded';
$_lang['mxboard_msg_id_copied'] = 'ID copied';
$_lang['mxboard_msg_saved'] = 'Saved';
$_lang['mxboard_msg_deadline_disputed'] = 'Deadline disputed';
$_lang['mxboard_msg_deadline_accepted'] = 'New deadline accepted';
$_lang['mxboard_msg_deadline_rejected'] = 'Dispute rejected';
$_lang['mxboard_msg_plan_disputed'] = 'Planned time disputed';
$_lang['mxboard_msg_plan_accepted'] = 'New estimate accepted';
$_lang['mxboard_msg_plan_rejected'] = 'Estimate dispute rejected';
$_lang['mxboard_msg_warn_proposed_hours'] = 'Specify the proposed estimate in hours';
$_lang['mxboard_msg_task_deleted'] = 'Task deleted';
$_lang['mxboard_msg_warn_proposed_date'] = 'Specify the proposed date';
$_lang['mxboard_msg_confirm_delete_task'] = 'Delete the task? Subtasks will be detached, not deleted.';
$_lang['mxboard_msg_discard_task'] = 'Close the form? Entered data will be lost.';

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

// Field type labels (in the type editor). DB key stays English.
$_lang['mxboard_ft_text'] = 'Text';
$_lang['mxboard_ft_textarea'] = 'Textarea';
$_lang['mxboard_ft_url'] = 'URL';
$_lang['mxboard_ft_number'] = 'Number';
$_lang['mxboard_ft_date'] = 'Date';
$_lang['mxboard_ft_select'] = 'Select';
$_lang['mxboard_ft_user'] = 'User';
$_lang['mxboard_ft_files'] = 'Files';

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
$_lang['mxboard_ui_struct_field_options'] = 'Options';
$_lang['mxboard_ui_struct_field_options_hint'] = 'Separated by | — e.g. low|medium|high';
$_lang['mxboard_ui_struct_field_min'] = 'A type must keep at least one field.';
$_lang['mxboard_ui_struct_confirm_remove_type'] = 'Delete type “[[+name]]”? Only if it has no tasks.';
$_lang['mxboard_ui_struct_ai_check'] = 'AI completeness check';
$_lang['mxboard_ui_struct_ai_prompt'] = 'AI check prompt (optional)';
$_lang['mxboard_ui_struct_ai_prompt_hint'] = 'Empty — the global prompt from system setting mxboard.ai_check_prompt is used.';
$_lang['mxboard_ui_struct_confirm_remove_field'] = 'Delete field “[[+name]]”?';
$_lang['mxboard_ui_struct_pick_department'] = 'Choose a department';

// Projects.
$_lang['mxboard_ui_struct_new_project'] = 'New project';
$_lang['mxboard_ui_struct_confirm_remove_project'] = 'Delete project “[[+name]]”? Only if it has no tasks.';
$_lang['mxboard_ui_struct_columns_from_template'] = 'No own columns yet — the board shows the default template. Copy columns to customize.';

// Columns/stages.
$_lang['mxboard_ui_struct_pick_project'] = 'Choose a project';
$_lang['mxboard_ui_struct_template'] = 'New-project template';
$_lang['mxboard_ui_struct_new_column'] = 'New column';
$_lang['mxboard_ui_struct_move_roles'] = 'Who can move a card here';
$_lang['mxboard_ui_struct_move_roles_hint'] = 'Who may move a card INTO this column.';
$_lang['mxboard_ui_struct_move_author'] = 'Author only';
$_lang['mxboard_ui_struct_move_assignee'] = 'Assignee only';
$_lang['mxboard_ui_struct_move_both'] = 'Author and assignee';
$_lang['mxboard_ui_struct_copy_columns'] = 'Copy columns';
$_lang['mxboard_ui_struct_copy_no_sources'] = 'No sources to copy columns from in this department.';
$_lang['mxboard_ui_struct_copy_source'] = 'Columns source';
$_lang['mxboard_ui_struct_copy_title'] = 'Copy columns';
$_lang['mxboard_ui_struct_copy_hint'] = 'Columns will be copied from the selected source. Available while the project has no tasks.';
$_lang['mxboard_ui_struct_source_default'] = 'Default (built-in template)';
$_lang['mxboard_ui_struct_readonly_hint'] = 'These are default columns (read-only). Copy columns to add and edit your own.';
$_lang['mxboard_ui_struct_reorder_hint'] = 'Drag rows to reorder columns.';
$_lang['mxboard_ui_struct_is_initial'] = 'Initial';
$_lang['mxboard_ui_struct_is_final'] = 'Final';
$_lang['mxboard_ui_struct_is_start'] = 'Start';
$_lang['mxboard_ui_struct_is_start_hint'] = 'Actual time is tracked from this stage. Returning to the initial stage resets it.';
$_lang['mxboard_ui_struct_color'] = 'Color';
$_lang['mxboard_ui_struct_description'] = 'Description';
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

// In-app notifications (SSE): panel title, empty state and per-type labels.
$_lang['mxboard_notify_title'] = 'Notifications';
$_lang['mxboard_notify_empty'] = 'No notifications yet';
$_lang['mxboard_notify_create'] = 'New task';
$_lang['mxboard_notify_move'] = 'Stage change';
$_lang['mxboard_notify_comment'] = 'Comment';
$_lang['mxboard_notify_close'] = 'Task closed';
$_lang['mxboard_notify_deadline_dispute'] = 'Deadline disputed';
$_lang['mxboard_notify_deadline_resolve'] = 'Deadline resolved';
$_lang['mxboard_notify_plan_dispute'] = 'Planned time disputed';
$_lang['mxboard_notify_plan_resolve'] = 'Planned time resolved';

// Reset project columns to defaults
$_lang['mxboard_err_reset_template'] = 'Default columns cannot be reset — this is the shared template.';
$_lang['mxboard_err_reset_no_own'] = 'The project has no own columns — nothing to reset.';
$_lang['mxboard_err_reset_no_template'] = 'There is no default column template to reset to.';
$_lang['mxboard_ui_struct_reset'] = 'Reset to defaults';
$_lang['mxboard_ui_struct_reset_confirm'] = 'Delete the project own columns and fall back to defaults? Tasks will move to the matching default stages.';
$_lang['mxboard_ui_struct_no_own'] = 'The project has no own columns — defaults are used. Click “Copy columns” to create your own and customize them.';

// Task queues: a project entity that drives the order tasks are started in.
$_lang['mxboard_err_queue_not_found'] = 'Queue not found.';
$_lang['mxboard_err_queue_denied'] = 'Only a department manager of the project can manage queues.';
$_lang['mxboard_err_queue_name_required'] = 'Queue requires a name.';
$_lang['mxboard_err_queue_key_exists'] = 'A queue with this key already exists in the project.';
$_lang['mxboard_err_queue_not_initial'] = 'Only a task in the initial stage can be added to a queue.';
$_lang['mxboard_err_queue_foreign_project'] = 'The queue belongs to another project.';
$_lang['mxboard_err_queue_required'] = 'The project has several queues — choose one.';
$_lang['mxboard_err_queue_none'] = 'The project has no queues.';
$_lang['mxboard_err_queue_task_not_in'] = 'The task is not in a queue.';
$_lang['mxboard_err_queue_no_start_column'] = 'The project has no start stage — there is nowhere to start the queue.';
$_lang['mxboard_queue_auto_note'] = 'Queue auto-start';

$_lang['mxboard_ui_struct_queues'] = 'Queues';
$_lang['mxboard_ui_struct_priorities'] = 'Priorities';
$_lang['mxboard_ui_struct_priorities_hint'] = 'Global priority dictionary — one for the whole system, not tied to any project. Value and name are unique; the last priority cannot be removed.';
$_lang['mxboard_ui_struct_new_priority'] = 'New priority';
$_lang['mxboard_ui_struct_priority_value'] = 'Value';
$_lang['mxboard_ui_struct_priority_value_hint'] = 'Integer: higher is more important. Written to the task priority.';
$_lang['mxboard_ui_struct_confirm_remove_priority'] = 'Remove priority “[[+name]]”?';
$_lang['mxboard_ui_queue'] = 'Queue';
$_lang['mxboard_ui_queues'] = 'Queues';
$_lang['mxboard_ui_queue_new'] = 'New queue';
$_lang['mxboard_ui_queue_project'] = 'Project';
$_lang['mxboard_ui_queue_add_task'] = 'To queue';
$_lang['mxboard_ui_queue_remove_task'] = 'Remove from queue';
$_lang['mxboard_ui_queue_select'] = 'Choose a queue';
$_lang['mxboard_ui_queue_added'] = 'Task added to the queue';
$_lang['mxboard_ui_queue_removed'] = 'Task removed from the queue';
$_lang['mxboard_ui_queue_in'] = 'In queue';
$_lang['mxboard_ui_queue_empty'] = 'The queue has no tasks';
$_lang['mxboard_ui_queue_confirm_remove'] = 'Delete the queue "[[+name]]"? Tasks stay, they just leave the queue.';
$_lang['mxboard_ui_queue_not_first'] = 'You are starting the queue, but this task is not the first one in it; continuing will change the order of tasks in the queue';
$_lang['mxboard_ui_queue_continue'] = 'Continue';
$_lang['mxboard_ui_queue_cancel'] = 'Cancel';
$_lang['mxboard_ui_queue_reordered'] = 'Queue order saved';
$_lang['mxboard_ui_queue_next'] = 'Next up';
$_lang['mxboard_ui_queue_hint'] = 'Reorder by dragging a row or pressing Alt+↑/↓. To start the queue, close this window and drag a task to the start stage.';
